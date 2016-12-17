<?php /* Дохуя Гарант v.20160223 © 12309 at exploit.in */ 

/* ## the plan:
-- до подтверждения клиентом статус 'new' и селлер может удалить сделку с помощью `code_seller`
-- после подтверждения клиентом генерится адрес `btc` и статус 'processing', а также меняется `url_buyer` чтобы ушлый продавец не подтвердил выдачу денег от имени покупателя
-- после полной оплаты или приёма частичной суммы продавцом статус 'paid' и `*_sec` описания показываются юзеру
-- если всё ок юзер подтверждает по `code_buyer`, статус становится 'complete' и селлер может забрать деньги и удалить сделку по `code_seller`
-- если проблема - генерится `code_dispute`, `url_dispute`, статус становится 'dispute' и вызывается арбитр сообщением в жабу
-- после решения проблемы арбитр подтверждает `code_dispute`, в сторону продавца: статус становится 'complete' и селлер может удалить сделку по `code_seller`; в сторону покупателя: статус становится 'paid' и покупатель может получить манибэк
-- после забирания денег продавцом или манибэка покупателю статус становится 'payout'

#TODO: вывод денег арбитру
#TODO: bug: при отправке сообщения в чат и смене языка последнее сообщение пересылается
#-> добавить csrf токен к форме чтобы f5 не работал
#TODO: ajax чат
#TODO: уведомление в жабу юзерам при смене статуса и сообщении в чате
*/

error_reporting(0); #RELEASE
ini_set('display_errors',0); #RELEASE
#error_reporting(E_ALL); #DEBUG
#ini_set('display_errors',1); #DEBUG
setlocale(LC_CTYPE, "en_US.UTF-8"); /* для корректной работы escapeshellarg с UTF8 */

$mysql_host = 'localhost';
$mysql_user = 'test';
$mysql_pass = '12345';
$mysql_db = 'test';
$btc_user = 'rpcuser';
$btc_pass = 'rpcpass';
$btc_host = '127.0.0.1';
$btc_port = 9998;
$btc_protocol = 'https';
$btc_fee = 0.0001; 
$dispute_fee = 6; /* процент арбитра */
$service_fee = 2; /* процент сервиса */
$max_file_size = 3145728; ## 3 MB
$admin = 'admin@jabber.ru';

/* максимальное расхождение в % от принятого и требуемого бабла. если из-за флуктуации курсов обменник отправил на $max_payment_diff процентов меньше, чем было нужно, то селлер может подтвердить оплату, не утруждая покупателя докидывать несколько копеек */
$max_payment_diff = 10; 

/* выгрузка файлов до коннекта к базе шоп не напрягать её */
if (!empty($_REQUEST['file']) && !empty($_REQUEST['name'])) {
  $file = trim($_REQUEST['file']);
  $name = trim($_REQUEST['name']);
  /* длина id файла всегда 40 */
  if (strlen($file) != 40) {
    $report = array();
    $report[] = "[[SERVER]]";
    foreach ($_SERVER as $key=>$value) $report[] = "$key = $value";
    $report[] = "[[REQUEST]]";
    foreach ($_REQUEST as $key=>$value) $report[] = "$key = $value";
    notify('HACKING ATTEMPT! line '.__LINE__.' DATA: '.implode(' | ',$report));
    die();
  }
  $filepath = 'uploads/'.substr($file, 0, 2).'/'.$file;
  header('Content-Length: '.filesize($filepath));
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="'.$name.'"');
  readfile($filepath);
  die();
}

$mysqli = mysqli_connect($mysql_host, $mysql_user, $mysql_pass, $mysql_db);

/* четыре разные страницы, отличаются по переданному параметру:
 index.php -- создание сделки продавцом
 index.php?seller=`url_seller` -- страница сделки продавца (чат, удаление сделки)
 index.php?buyer=`url_buyer` -- страница сделки покупателя (чат, оплата, релиз/блок денег)
 index.php?dispute=`url_dispute` -- управление арбитром (чат, снятие блока денег)
*/

$seller = isset($_REQUEST['seller']) ? $_REQUEST['seller'] : '';
$buyer = isset($_REQUEST['buyer']) ? $_REQUEST['buyer'] : '';
$dispute = isset($_REQUEST['dispute']) ? $_REQUEST['dispute'] : '';

/* переменная в урле может быть только одна и её длина всегда 40 */
if (strlen($seller.$buyer.$dispute) > 40 or (strlen($seller.$buyer.$dispute) > 1 and strlen($seller.$buyer.$dispute) < 40)) {
  $report = array();
  $report[] = "[[SERVER]]";
  foreach ($_SERVER as $key=>$value) $report[] = "$key = $value";
  $report[] = "[[REQUEST]]";
  foreach ($_REQUEST as $key=>$value) $report[] = "$key = $value";
  notify('HACKING ATTEMPT! line '.__LINE__.' DATA: '.implode(' | ',$report));
  die();
}

$seller = esc($seller);
$buyer = esc($buyer);
$dispute = esc($dispute);
$error = '';




/* =-=-=-=-=-=-=-=-=-=-=-=  создание сделки  =-=-=-=-=-=-=-=-=-=-=-=-= */
if (strlen($seller.$buyer.$dispute) == 0) {

  $description_pub = (empty($_POST['description_pub'])) ? '' : $_POST['description_pub'];
  if (empty($description_pub)) $error.= "empty public description<br>";
  $description_pub_tosql = base64_encode($description_pub); 
  $description_sec = (empty($_POST['description_sec'])) ? '' : $_POST['description_sec'];
  if (empty($description_sec)) $error.= "empty secret description<br>";
  $description_sec_tosql = base64_encode($description_sec); 
  $price = (empty($_POST['price'])) ? '' : $_POST['price'];
  $price = trim(str_replace(',','.',str_replace(' ','',$price)));
  if (!is_numeric($price)) {
    if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') {
      $error .= "неправильный формат цены<br>";
    } else {
      $error .= "wrong price format<br>";
    }
  }
  else $price = round($price, 8);
  if (empty($price)) $error.= "empty price<br>";
  $price_tosql = esc($price);
  $store_days = (empty($_POST['store_days'])) ? '7' : $_POST['store_days'];
  if (empty($store_days)) $error.= "empty store days<br>";
  $store_days_tosql = (int)$store_days;

  /* сохраняем залитые файлы */
  if (empty($error) and !empty($_REQUEST['GO'])) {
    $uploaded_files_pub = array();
    $files_pub = array('files_pub_1','files_pub_2','files_pub_3');
    foreach ($files_pub as $file) {
      if (!empty($_FILES["$file"]['name']) && $_FILES["$file"]['error'] == 0) {
        if ($_FILES["$file"]["size"] > $max_file_size) {
          if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') {
            $error .= "Слишком большой файл ".$_FILES["$file"]['name']."!<br>";
          } else {
            $error .= "Too big file ".$_FILES["$file"]['name']."!<br>";
          }  
          break;
        }
        /* переименовываем шоп не залили шелл */
        $newname = gen();
        /* раскидывам по разным папкам чтобы не забивать ФС */
        $newpath = 'uploads/'.substr($newname, 0, 2);
        @mkdir($newpath,0755,true);
        if (move_uploaded_file($_FILES["$file"]['tmp_name'], $newpath.'/'.$newname)) {
          $uploaded_files_pub[] = array('name' => esc($_FILES["$file"]['name']), 'path' => $newname);
        } else {
          $error .= "ERROR WTF failed to upload ".$_FILES["$file"]['name']."!<br>";
        }
      }
    }
  }

  /* проверка на акунетикс */
  $ip_address = sha1(sha1(sha1(sha1($_SERVER['REMOTE_ADDR']))));
  if (empty($error) and !empty($_REQUEST['GO'])) {
    $sql = 'SELECT * FROM `deals` WHERE `ip_address` = "'.$ip_address.'" AND `status` = "new" ORDER BY `changed` DESC LIMIT 1;';
    $result = sql($sql);
    $last_deal = mysqli_fetch_assoc($result);
    if (!empty($last_deal) and (time() - $last_deal['changed']) < 300) {
      if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') {
        $error .= "Последняя сделка создана < 5 минут назад!<br>";
      } else {
        $error .= "Last deal created < 5 minutes ago!<br>";
      }
    }
  }

  if (empty($error) and !empty($_REQUEST['GO'])) {
    $uploaded_files_sec = array();
    $files_sec = array('files_sec_1','files_sec_2','files_sec_3');
    foreach ($files_sec as $file) {
      if (!empty($_FILES["$file"]['name']) && $_FILES["$file"]['error'] == 0) {
        if ($_FILES["$file"]["size"] > $max_file_size) {
          if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') {
            $error .= "Слишком большой файл ".$_FILES["$file"]['name']."!<br>";
          } else {
            $error .= "Big file ".$_FILES["$file"]['name']."!<br>";
          }
          break;
        }
        /* переименовываем шоп не залили шелл */
        $newname = gen();
        /* раскидывам по разным папкам чтобы не забивать ФС */
        $newpath = 'uploads/'.substr($newname, 0, 2);
        @mkdir($newpath,0755,true);
        if (move_uploaded_file($_FILES["$file"]['tmp_name'], $newpath.'/'.$newname)) {
          $uploaded_files_sec[] = array('name' => esc($_FILES["$file"]['name']), 'path' => $newname);
        } else {
          $error .= "ERROR WTF failed to upload ".$_FILES["$file"]['name']."!<br>";
        }
      }
    }
  }

  if (!empty($error) and !empty($_REQUEST['GO'])) {
    /* удаляем залитое файло */
    foreach ($uploaded_files_pub as $id=>$file) {
      $dir = 'uploads/'.substr($file['path'], 0, 2);
      @unlink($dir.'/'.$file['path']);
    }
    foreach ($uploaded_files_sec as $id=>$file) {
      $dir = 'uploads/'.substr($file['path'], 0, 2);
      @unlink($dir.'/'.$file['path']);
    }
  }

  if (empty($error) and !empty($_REQUEST['GO'])) {
    $uploaded_files_pub_tosql = base64_encode(json_encode($uploaded_files_pub));
    $uploaded_files_sec_tosql = base64_encode(json_encode($uploaded_files_sec));
    $code_seller_tosql = gen(3);
    $url_seller_tosql = gen();
    $url_buyer_tosql = gen();

    $sql = 'INSERT INTO `deals` ( 
`id`, 
`ip_address`,
`changed`,
`status`, 
`description_pub`, 
`description_sec`, 
`files_pub`, 
`files_sec`, 
`price`, 
`store_days`, 
`code_seller`, 
`url_seller`, 
`url_buyer` 
) VALUES (
NULL,
"'.$ip_address.'",
UNIX_TIMESTAMP(NOW()),
"new",
"'.$description_pub_tosql.'",
"'.$description_sec_tosql.'",
"'.$uploaded_files_pub_tosql.'",
"'.$uploaded_files_sec_tosql.'",
"'.$price_tosql.'",
"'.$store_days_tosql.'",
"'.$code_seller_tosql.'",
"'.$url_seller_tosql.'",
"'.$url_buyer_tosql.'"
)';
  
    if(sql($sql)) {
      /* успешное создание сделки, переходим к управлению */
      notify('NEW DEAL: http://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME']).'/index.php?seller='.$url_seller_tosql); #DEBUG
      header('Location: index.php?seller='.$url_seller_tosql);
    }
  }
 
  ?>
<!DOCTYPE html>
<html>
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
  <title>Создание сделки</title>
<? } else { ?>
  <title>Deal creation</title>
<? } ?>
  <style>
  textarea { overflow-x: hidden; }
  </style>
</head>
<body>
<noscript><p style="color:red;text-align:center;">PLEASE ENABLE JAVASCRIPT</p></noscript>
  <?
  if (!empty($error) and !empty($_REQUEST['GO'])) {
    echo '<br><p style="color:red;text-align:center;"><b>ERROR: '.$error.'</b></p><br>';
  }
  ?>
<form method="POST" action="" enctype="multipart/form-data">
<table border="0" width="900px" align="center">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
<tr><td></td><td style="text-align:center;"><a href="#" onclick="document.cookie='lang=en;';location.reload();">ENGLISH</a><br><br></td></tr>
<? } else { ?>
<tr><td></td><td style="text-align:center;"><a href="#" onclick="document.cookie='lang=ru;';location.reload();">РУССКИЙ</a><br><br></td></tr>
<? } ?>
<tr>
  <td align="left"><b>
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    публичное описание<br>
    видно до оплаты
<? } else { ?>
    public description<br>
    available before payment
<? } ?>
  </b></td>
  <td align="right">
    <hr><br>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    описание товара
<? } else { ?>
    item description
<? } ?>
  </td>
  <td align="right"><span style="color:grey;">(max. 40000 symbols)</span><br>
    <textarea name="description_pub" rows="5" cols="40"><?=htmlspecialchars($description_pub)?></textarea>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    скриншоты, демо программы,<br>
    тестовые выборки баз, ... 
<? } else { ?>
    screenshots, database examples,<br>
    program trial version, ...
<? } ?>
  </td>
  <td align="right">
    <input name="files_pub_1" type="file" /><span style="color:grey;">(max. 3 MB)</span><br>
    <input name="files_pub_2" type="file" /><span style="color:grey;">(max. 3 MB)</span><br>
    <input name="files_pub_3" type="file" /><span style="color:grey;">(max. 3 MB)</span>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left"><b>
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    секретное описание<br>
    видно после оплаты
<? } else { ?>
    secret description<br>
    available after payment
<? } ?>
  </b></td>
  <td align="right">
     <hr><br>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    полное описание, ссылки на <br>
    залитые файлы, пароли, ... 
<? } else { ?>
    full description, links to<br>
    uploaded files, passwords, ...
<? } ?>
  </td>
  <td align="right"><span style="color:grey;">(max. 1000000 symbols)</span><br>
    <textarea name="description_sec" rows="5" cols="40"><?=htmlspecialchars($description_sec)?></textarea>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    полные базы, полные<br>
    версии программ, ...<br>
<? } else { ?>
    full databases, full<br>
    program versions, ...<br>
<? } ?>
  </td>
  <td align="right">
    <input name="files_sec_1" type="file" /><span style="color:grey;">(max. 3 MB)</span><br>
    <input name="files_sec_2" type="file" /><span style="color:grey;">(max. 3 MB)</span><br>
    <input name="files_sec_3" type="file" /><span style="color:grey;">(max. 3 MB)</span>
  </td>
</tr>
<tr><td><br></td></tr>

<script type="text/javascript">
  function calc() {
    document.getElementById('you_will_get').innerHTML = parseFloat((document.getElementById('price').value - <?=$btc_fee?> - (document.getElementById('price').value * (<?=$dispute_fee?>/100)) - (document.getElementById('price').value * (<?=$service_fee?>/100))).toFixed(8));
    document.getElementById('you_will_get2').innerHTML = parseFloat((document.getElementById('price').value - <?=$btc_fee?> - (document.getElementById('price').value * (<?=$service_fee?>/100))).toFixed(8));
    document.getElementById('service_fee').innerHTML = parseFloat((document.getElementById('price').value * (<?=$service_fee?>/100)).toFixed(8));
    document.getElementById('dispute_fee').innerHTML = parseFloat((document.getElementById('price').value * (<?=$dispute_fee?>/100)).toFixed(8));
  }
</script>
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    цена в BTC
<? } else { ?>
    price in BTC
<? } ?>
  </td>
  <td align="center">
    <input name="price" id="price" type="text" size="20" maxlength="20" value="<?=$price?>" onKeyDown="calc();" onKeyUp="calc();" onKeyPress="calc();" />
  </td>
</tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    вы получите (без арбитража / с арбитражем)
<? } else { ?>
    you will get (no arbitrage / with arbitrage)
<? } ?>
  </td>
  <td align="center">
    <span id="you_will_get2" name="you_will_get2"><?=$price?></span> / <span id="you_will_get" name="you_will_get"><?=$price?></span> BTC
  </td>
</tr>
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    <span style="color:grey;">комиссия Bitcoin</span>
<? } else { ?>
    <span style="color:grey;">Bitcoin network fee</span>
<? } ?>
  </td>
  <td align="center">
    <span style="color:grey;">~ <?=$btc_fee?> BTC</span>
  </td>
</tr>
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    <span style="color:grey;">комиссия сервиса</span>
<? } else { ?>
    <span style="color:grey;">service fee</span>
<? } ?>
  </td>
  <td align="center">
    <span style="color:grey;"><?=$service_fee?> % (<span id="service_fee" name="service_fee"></span>) </span>
  </td>
</tr>
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    <span style="color:grey;">комиссия арбитра</span>
<? } else { ?>
    <span style="color:grey;">arbitrage fee</span>
<? } ?>
  </td>
  <td align="center">
    <span style="color:grey;"><?=$dispute_fee?> % (<span id="dispute_fee" name="dispute_fee"></span>) </span>
  </td>
</tr>

<tr>
  <td align="left" style="font-size:0.8em;"><br>
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    <span style="color:grey;">указана минимальная комиссия сети Bitcoin. она может быть выше при сложной транзакции (например, если покупатель для оплаты сделал множество мелких переводов с разных кошельков вместо одного перевода с одного кошелька)<br>
    комиссия арбитра возьмётся только если к сделке будет привлечён арбитр. если сделка пройдёт успешно без разбирательств, комиссия арбитра не взимается<br></span>
<? } else { ?>
    <span style="color:grey;">minimal Bitcoin network fee shown. it could be higher with the complex transaction (for example if the buyer made many small transfers from different accounts instead of making single payment from single account)<br>
    arbitrage fee will be deducted only if admin was called for dispute. if the deal finishes successfully without disputes arbitrage fee will not be deducted<br></span>
<? } ?>
  </td>
  <td> 
  </td>
</tr>

<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    таймаут сделки, дней<br>
<? } else { ?>
    deal timeout, days<br>
<? } ?>
  </td>
  <td align="right">
    1<input type="radio" name="store_days" value="1" <?=($store_days == '1') ? 'checked' : ''?>> 
    3<input type="radio" name="store_days" value="3" <?=($store_days == '3') ? 'checked' : ''?>> 
    7<input type="radio" name="store_days" value="7" <?=($store_days == '7') ? 'checked' : ''?>>
    14<input type="radio" name="store_days" value="14" <?=($store_days == '14') ? 'checked' : ''?>> 
    30<input type="radio" name="store_days" value="30" <?=($store_days == '30') ? 'checked' : ''?>>
    60<input type="radio" name="store_days" value="60" <?=($store_days == '60') ? 'checked' : ''?>>
    90<input type="radio" name="store_days" value="90" <?=($store_days == '90') ? 'checked' : ''?>>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left" style="font-size:0.8em;">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?> 
    <span style="color:grey;">новая сделка (статус 'new') удалится, если покупатель не согласится с ней за этот таймаут.<br>
    сделка в работе (статус 'processing') удалится, если клиент не оплатит её за таймаут.<br>
    оплаченная сделка (статус 'paid') станет завершённой (статус 'complete'), если у покупателя не возникнет претензий за таймаут.<br>
    завершённая сделка ('complete') удалится через таймаут, если у покупателя не возникнет претензий за таймаут.</span>
<? } else { ?>
    <span style="color:grey;">'new' deal will autodelete if the buyer won't confirm it before the timeout.<br>
    'processing' deal will autodelete if the buyer won't pay for in before the timeout.<br>
    'paid' deal will become 'complete' if the buyer won't ask for the dispute before the timeout.<br>
    'complete' deal will autodelete if the buyer won't ask for the dispute before the timeout.</span>
<? } ?>
  </td>
  <td>
  </td>
</tr>

<tr><td><br><br></td></tr>

<tr>
  <td></td>
  <td align="center">
    <input type="submit" name="GO" value="GO" />
  </td>
  <td></td>
</tr>
<tr><td><br><br><br><br><br></td></tr>

</table>
</form>
</body>
</html>
  <?
}




/* =-=-=-=-=-=-=-=-=-=-=-=  управление сделкой - продавец  =-=-=-=-=-=-=-=-=-=-=-=-= */
if (!empty($seller)) {
  $url_seller = $seller;
  $sql = 'SELECT * FROM `deals` WHERE `url_seller` = "'.$url_seller.'"';
  $result = sql($sql);
  $deal_info = mysqli_fetch_assoc($result);
  if (empty($deal_info)) { 
    $report = array();
    $report[] = "[[SERVER]]";
    foreach ($_SERVER as $key=>$value) $report[] = "$key = $value";
    $report[] = "[[REQUEST]]";
    foreach ($_REQUEST as $key=>$value) $report[] = "$key = $value";
    notify('HACKING ATTEMPT! line '.__LINE__.' DATA: '.implode(' | ',$report));
    die();
  }
  $deal_id = $deal_info['id'];
  $status = $deal_info['status'];
  $description_pub = htmlspecialchars(base64_decode($deal_info['description_pub']));
  $description_sec = htmlspecialchars(base64_decode($deal_info['description_sec']));
  $files_pub = json_decode(base64_decode($deal_info['files_pub']), true);
  $files_sec = json_decode(base64_decode($deal_info['files_sec']), true);
  $price = rtrim($deal_info['price'],0);
  $btc = $deal_info['btc'];
  $code_seller = $deal_info['code_seller'];
  $url_buyer = $deal_info['url_buyer'];
  $url_dispute = $deal_info['url_dispute'];
  $store_days = $deal_info['store_days'];
  $changed = $deal_info['changed'];
  $payout = $deal_info['payout'];
  $timeout = ($store_days * 24 * 60 * 60) - (time() - $changed);
  if ($timeout < 0 and $status != 'dispute') {
    foreach ($files_pub as $id=>$file) {
      $dir = 'uploads/'.substr($file['path'], 0, 2);
      @unlink($dir.'/'.$file['path']);
    }
    foreach ($files_sec as $id=>$file) {
      $dir = 'uploads/'.substr($file['path'], 0, 2);
      @unlink($dir.'/'.$file['path']);
    }
    /* удаляем из бд */
    $sql = 'DELETE FROM `deals` WHERE `id` = "'.$deal_id.'"';
    $result = sql($sql);
    if ($result == true) {
      die();
    }
  }  
  $delete_in_d = floor($timeout / (60 * 60 * 24));
  $timeout -= $delete_in_d * (60 * 60 * 24);
  $delete_in_h = floor($timeout / (60 * 60));
  $timeout -= $delete_in_h * (60 * 60);
  $delete_in_m = floor($timeout / 60);
  $timeout -= $delete_in_m * 60;
  $delete_in_s = floor($timeout);
  $timeout -= $delete_in_s;
  $delete_in = "{$delete_in_d}d {$delete_in_h}h {$delete_in_m}m {$delete_in_s}s";
  
  if (!empty($_REQUEST['DELETE']) and $status == 'new') {
    $delete_code = esc($_POST['code_1']).esc($_POST['code_2']).esc($_POST['code_3']);
    if ($delete_code != $code_seller) {
      if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') {
        $error .= "неправильный код!<br>";
      } else {
        $error .= "wrong code!<br>";
      }
    } else {
      /* удаляем залитое файло */
      foreach ($files_pub as $id=>$file) {
        $dir = 'uploads/'.substr($file['path'], 0, 2);
        @unlink($dir.'/'.$file['path']);
      }
      foreach ($files_sec as $id=>$file) {
        $dir = 'uploads/'.substr($file['path'], 0, 2);
        @unlink($dir.'/'.$file['path']);
      }
      /* удаляем из бд */
      $sql = 'DELETE FROM `deals` WHERE `id` = "'.$deal_id.'"';
      $result = sql($sql);
      if ($result == true) {
        header('Location: index.php');
      }
    }
  }
  
  if (!empty($_REQUEST['chat']) and !empty($_REQUEST['message'])) {
    if (mb_strlen($_REQUEST['message']) > 200) {
      $error .= "message > 200 symbols!<br>";
    } else {
      $message = base64_encode($_REQUEST['message']);
      $sql = 'INSERT INTO `chat` (`deal_id`, `author`, `unixtime`, `message`) VALUES ("'.$deal_id.'", "seller", UNIX_TIMESTAMP(NOW()), "'.$message.'")';
      $result = sql($sql);
    }
  }

  if ($status == 'processing') {
    $balance = get_balance($deal_id);
    $percent_paid = round((($balance['confirmed'] / $price) * 100),1);
    $percent_remain = 100 - $percent_paid;

    if ($balance['confirmed'] >= $price or !empty($_REQUEST['okay'])) {
      $sql = 'UPDATE `deals` SET `status` = "paid", `changed` = UNIX_TIMESTAMP(NOW()) WHERE `id` = "'.$deal_id.'"';
      $result = sql($sql);
      if ($result == true) {
        header('Location: index.php?seller='.$url_seller);
      } else {
        notify("failed to run sql '".$sql."'");
      }
    }
  }

  /* запрос на арбитраж */
  if (!empty($_REQUEST['DISPUTE'])) {
    $code_dispute_tosql = gen(3);
    $url_dispute_tosql = gen();
    $sql = 'UPDATE `deals` SET `status` = "dispute", `changed` = UNIX_TIMESTAMP(NOW()), `code_dispute` = "'.$code_dispute_tosql.'", `url_dispute` = "'.$url_dispute_tosql.'" WHERE `id` = "'.$deal_id.'"';
    $result = sql($sql);
    if ($result == true) {
      if ($_SERVER['HTTPS'] == 'on') {
        $link_for_admin = 'https://';
      } else {
        $link_for_admin = 'http://';
      }
      $link_for_admin .= $_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME']).'/index.php?dispute='.$url_dispute_tosql;
      notify("NEW REQUEST FOR ARBITRAGE: $link_for_admin");
      header('Location: index.php?seller='.$url_seller);
    }
  }

  /* вывод бабла */
  if (!empty($_REQUEST['GET_MONEY']) and !empty($_POST['address'])) {
    $balance = get_balance($deal_id);
    $confirm_code = esc($_POST['code_1']).esc($_POST['code_2']).esc($_POST['code_3']);
    if ($confirm_code != $code_seller) {
      if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') {
        $error .= "неправильный код!<br>";
      } else {
        $error .= "wrong code!<br>";
      }
    } else {
      $address = esc($_POST['address']);
      if (!empty($url_dispute)) {
        /* был диспут, даём % арбитру */
        $minus = ($balance['confirmed'] + $balance['unconfirmed']) * ($dispute_fee / 100);
        $sql = 'INSERT INTO `payouts` (`created`, `owner`, `amount`) VALUES (UNIX_TIMESTAMP(NOW()), "dispute", "'.$minus.'")';
        $result = sql($sql);
      } else {
        $minus = 0;
      }
      if (!empty($service_fee)) {
        $service_payout = ($balance['confirmed'] + $balance['unconfirmed']) * ($service_fee / 100);
        $sql = 'INSERT INTO `payouts` (`created`, `owner`, `amount`) VALUES (UNIX_TIMESTAMP(NOW()), "service", "'.$service_payout.'")';
        $result = sql($sql);
      }
      $amount = round((($balance['confirmed'] + $balance['unconfirmed']) - $btc_fee - ($balance['confirmed'] + $balance['unconfirmed']) * ($service_fee/100) - $minus),8);
      $transaction = send_btc($deal_id,$address,$amount);
      if ($transaction) {
        $sql = 'UPDATE `deals` SET `status` = "payout", `changed` = UNIX_TIMESTAMP(NOW()), `payout` = "'.$address.'" WHERE `id` = "'.$deal_id.'"';
        $result = sql($sql);
        if (!$result) {
          $error .= "failed to update database!<br>";
          notify("failed to run sql '".$sql."'");
        }
        header('Location: index.php?seller='.$url_seller);
      } else {
        $error .= "failed to send money!<br>";
      }
    }
  }

  ?>
<!DOCTYPE html>
<html>
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
  <title>Управление сделкой</title>
<? } else { ?>
  <title>Deal management</title>
<? } ?>
  <style>
  textarea { overflow-x: hidden; }
  </style>
</head>
<body>
<noscript><p style="color:red;text-align:center;">PLEASE ENABLE JAVASCRIPT</p></noscript>
  <?
  if (!empty($error) and (!empty($_REQUEST['DELETE']) or !empty($_REQUEST['chat']) or !empty($_REQUEST['GET_MONEY']))) { 
    echo '<br><p style="color:red;text-align:center;"><b>ERROR: '.$error.'</b></p><br>';
  }
  ?>
<form method="POST" action="" enctype="multipart/form-data">
<table border="0" width="900px" align="center">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
<tr><td></td><td style="text-align:center;"><a href="#" onclick="document.cookie='lang=en;';location.reload();">ENGLISH</a><br><br></td></tr>
<? } else { ?>
<tr><td></td><td style="text-align:center;"><a href="#" onclick="document.cookie='lang=ru;';location.reload();">РУССКИЙ</a><br><br></td></tr>
<? } ?>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    статус сделки
<? } else { ?>
    deal status
<? } ?>
  </td>
  <td align="center">
   <b><?=$status?></b>
  </td>
</tr>
<tr><td><br></td></tr>

  <?
  if ($status != 'payout') {
  ?>
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    ваш код подтверждения
<? } else { ?>
    your confirmation code
<? } ?>
  </td>
  <td align="center">
   <b><?=$code_seller?></b>
  </td>
</tr>
<tr><td><br></td></tr>
  <?
  }
  ?>

  <? 
  if ($status != 'new') { 
  ?>
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    адрес для оплаты
<? } else { ?>
    payment address
<? } ?>
  </td>
  <td align="center">
   <a href="https://blockchain.info/address/<?=$btc?>" target="_blank"><?=$btc?></a>
  </td>
</tr>
<tr><td><br></td></tr>
  <?
  }

  if ($status == 'complete') {
  ?>
<tr>
  <td align="left" style="font-size:0.8em;">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    сделка завершена. теперь <br>
    вы можете забрать деньги
<? } else { ?>
    the deal is complete. now<br>
    you could receive money<br>
<? } ?>
  </td>
  <td align="right">
    <hr>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left" style="font-size:0.8em;">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    введите ваш кошелёк Bitcoin<br>
    и код для подтверждения вывода
<? } else { ?>
    enter your Bitcoin address<br>
    and the code for confirmation
<? } ?>
  </td>
  <td align="center">
    <input type="text" size="1" maxlength="1" name="code_1" style="width:5%;" />
    <input type="text" size="1" maxlength="1" name="code_2" style="width:5%;" />
    <input type="text" size="1" maxlength="1" name="code_3" style="width:5%;" />
    <br>
    <input type="text" size="34" maxlength="35" name="address" id="address" />
    <br>
    <input type="submit" name="GET_MONEY" value="GET MONEY" onclick="javascript:return confirm('confirm ' + document.getElementById('address').value)"  />
  </td>
</tr>
  <?
  }

  if ($status == 'payout' and empty($url_dispute)) {
  ?>
<tr>
  <td align="left" style="font-size:0.8em;">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    сделка завершена. деньги<br>
    отправлены на адрес 
<? } else { ?>
    the deal is complete. <br>
    money sent to the address <br>
<? } ?>
  </td>
  <td align="center">
   <a href="https://blockchain.info/address/<?=$payout?>" target="_blank"><?=$payout?></a>
  </td>
</tr>
<tr><td><br></td></tr>

  <?
  }

  if ($status == 'processing') {
  ?>
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    получено
<? } else { ?>
    received
<? } ?>
  </td>
  <td align="center">
   <?=$balance['confirmed']?> / <?=$price?> (<?=$percent_paid?>%) 
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    вы получите
<? } else { ?>
    you will get
<? } ?>
  </td>
  <td align="center">
    <span id="you_will_get" name="you_will_get"><?=round(($balance['confirmed'] - $btc_fee - $balance['confirmed']*($dispute_fee/100) - $balance['confirmed']*($service_fee/100)),8)?></span>
  </td>
</tr>
<tr><td><br></td></tr>


  <?
    if ($percent_remain <= $max_payment_diff) {
    ?>
<tr><td><br><br></td></tr>

<tr>
  <td align="left" style="font-size:0.8em;">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    разница между полученной<br>
    и требуемой суммой меньше<br>
    чем <?=$max_payment_diff?>%. для удобства покупателя<br>
    вы можете принять оплату сейчас
<? } else { ?>
    the difference between received<br>
    and required sum is less than <?=$max_payment_diff?>%<br>
    for buyer convenience you could<br>
    accept the payment now
<? } ?>
  </td>
  <td align="center">
    <input type="text" size="1" maxlength="1" name="code_1" style="width:5%;" />
    <input type="text" size="1" maxlength="1" name="code_2" style="width:5%;" />
    <input type="text" size="1" maxlength="1" name="code_3" style="width:5%;" />
    <br><br>
    <input type="submit" name="okay" value="okay" onclick="javascript:return confirm('<?=$percent_paid?>% money OK?')" />
  </td>
</tr>
    <?
    }
  }

  if ($status == 'new') {
  ?>
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    <b>ссылка для покупателя</b>
<? } else { ?>
    <b>link for buyer</b>
<? } ?>
  </td>
  <td align="center">
   <b><a href="index.php?buyer=<?=$url_buyer?>" target="_blank">click</a></b>
  </td>
</tr>
<tr><td><br></td></tr>
  
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    вы можете удалить сделку <br>
    пока у неё статус 'new'.<br>
    для удаления введите ваш код
<? } else { ?>
    you can delete the deal <br>
    while its status is 'new'<br>
    enter your code to delete
<? } ?>
  </td>
  <td align="center">
    <input type="text" size="1" maxlength="1" name="code_1" style="width:5%;" /> 
    <input type="text" size="1" maxlength="1" name="code_2" style="width:5%;" />
    <input type="text" size="1" maxlength="1" name="code_3" style="width:5%;" />
    <br><br>
    <input type="submit" name="DELETE" value="DELETE" onclick="javascript:return confirm('really delete?')" />
  </td>
</tr>
  <?
  }
  ?>

<tr><td><br><br></td></tr>


  <?
  if ($status == 'paid') {
    if (empty($url_dispute)) {
  ?>

<tr>
  <td align="left" style="font-size:0.8em;">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    требуемая сумма получена.<br>
    теперь у покупателя выводятся<br>
    секретные данные товара,<br>
    подождите, пока он их проверит 
<? } else { ?>
    the payment has been received.<br>
    now the buyer could see the <br>
    secret information. please wait<br>
    until he verifies the goods
<? } ?>
  </td>
  <td align="right">
    <hr>
  </td>
</tr>
<tr><td><br><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    если вас не устраивает сделка вы можете вызвать админа для разборок
<? } else { ?>
    if you are not satisfied with the deal you could call the admin for arbitrage
<? } ?>
  </td>
  <td align="right">
    <input type="submit" name="DISPUTE" value="DISPUTE" onclick="javascript:return confirm('really dispute?')" />
  </td>
</tr>
<tr><td><br></td></tr>
    <?
    } else { 
    ?>
<tr>
  <td align="left" style="font-size:0.8em;">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    арбитраж решён в сторону клиента.<br>
    теперь он может получить манибэк
<? } else { ?>
    arbitrage was resolved to buyer side.<br>
    now he could get the moneyback
<? } ?>
  </td>
  <td align="right">
    <hr>
  </td>
</tr>
<tr><td><br><br></td></tr>
    <?
    }
  } 

  if ($status == 'dispute') {
  ?>
<tr>
  <td align="left" style="font-size:0.8em;">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    <b>вызван админ для разборок.<br>
    ждите результатов проверки<br>
    вы можете пользоваться чатом</b><br>
<? } else { ?>
    <b>admin was called for arbitrage.<br>
    wait for verification results<br>
    you could use the chat<br>
<? } ?>
  </td>
  <td align="right">
    <hr>
  </td>
</tr>
  <?
  }
  ?>

<tr><td><br><br></td></tr>

<tr>
  <td align="center">
    <input type="submit" name="REFRESH" value="REFRESH" />
  </td>
</tr>

<tr><td><br></td></tr>
</table>
</form>

<hr>

<form method="POST" action="" enctype="multipart/form-data">
<script type="text/javascript"> 
function calc_limit() {
  var max = 200;
  var text = document.getElementById('message').value;
  var len = document.getElementById('message').value.length;
  var limit = (max - len);
  document.getElementById('message_limit').innerHTML = limit;
  if (len > max) {
    text = text.substring(0,max);
    document.getElementById('message').value = text;
  }
}
</script>
<table border="0" width="900px" align="center">
<tr><td><br></td></tr>

  <? /* чятик */
  $sql = 'SELECT `author`,`unixtime`,`message` FROM `chat` INNER JOIN `deals` ON `deals`.`id` = `chat`.`deal_id` WHERE `deals`.`id` = "'.$deal_id.'" ORDER BY `unixtime` ASC';
  $result = sql($sql);
  ?>
  <tr align="center">
    <td style="width:33%;"></td>
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    <td style="width:33%;"><b>Чат</b></td>
<? } else { ?>
    <td style="width:33%;"><b>Chat</b></td>
<? } ?>
    <td style="width:33%;"></td>
  </tr>
  <tr><td><br></td></tr>
  <? 
  if (mysqli_num_rows($result) > 0) {
  ?>
  <tr align="center">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    <td style="width:33%;">- Время -</td>
    <td style="width:33%;">- Продавец -</td>
    <td style="width:33%;">- Покупатель -</td>
<? } else { ?>
    <td style="width:33%;">- Time -</td>
    <td style="width:33%;">- Seller -</td>
    <td style="width:33%;">- Buyer -</td>
<? } ?>
  </tr>
  <?
  }
  foreach ($result as $chat) {
    $chat_author = $chat['author'];
    $chat_time = date("Y-m-d H:i:s",$chat['unixtime']);
    $chat_message = htmlentities(base64_decode($chat['message']));
    switch ($chat_author) {
      case 'seller':
        echo "<tr><td>$chat_time</td> <td><textarea rows='2' cols='40'>$chat_message</textarea></td> <td></td></tr>";
        break;
      case 'buyer':
        echo "<tr><td>$chat_time</td> <td></td> <td><textarea rows='2' cols='40'>$chat_message</textarea></td></tr>";
        break;
      case 'dispute':
        echo "<tr><td>$chat_time</td> <td><span style='color:red;'><b>ADMIN COMMENT:</b></span></td> <td><textarea rows='2' cols='40' style='color:red;'>$chat_message</textarea></td>";
        break;
    }
  }
  ?>
<tr><td><br></td></tr>

<tr>
  <td><span style="color:grey;">(max. 200 symbols)</span></td>
  <td align="center" style="display:inline-block;vertical-align:middle;">
    <textarea rows="2" cols="34" name="message" id="message" onKeyDown="calc_limit();" onKeyUp="calc_limit();" onKeyPress="calc_limit();"></textarea><span id="message_limit" name="message_limit" style="color:grey;">200</span>
  </td>
  <td><input type="submit" name="chat" value="send" onclick="javascript:return confirm('send message?');" /></td>
</tr>

<tr><td><br><br></td></tr>

</table>
</form>

<hr>

<form method="POST" action="" enctype="multipart/form-data">
<table border="0" width="900px" align="center">

<tr><td><br><br></td></tr>

<tr>
  <td align="left"><b>
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    публичное описание<br>
    видно до оплаты
<? } else { ?>
    public description<br>
    available before payment
<? } ?>
  </b></td>
  <td align="right">
    <hr><br>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    описание товара
<? } else { ?>
    item description
<? } ?>
  </td>
  <td align="right">
    <textarea rows="5" cols="40"><?=$description_pub?></textarea>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    скриншоты, демо программы, <br>
    тестовые выборки баз, ... 
<? } else { ?>
    screenshots, database examples, <br>
    program trial version, ...
<? } ?>
  </td>
  <td align="center">
    <b>
    <? 
    if (empty($files_pub)) {
      echo "---";
    } else {
      foreach($files_pub as $file) {
        echo '<a href="index.php?file='.$file['path'].'&name='.$file['name'].'" target="_blank">'.$file['name'].'</a><br>';
      }
    }
    ?>
    </b>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left"><b>
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    секретное описание<br>
    видно после оплаты
<? } else { ?>
    secret description<br>
    available after payment
<? } ?>
  </b></td>
  <td align="right">
    <hr><br>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    полное описание, ссылки на <br>
    залитые файлы, пароли, ... 
<? } else { ?>
    full description, links to<br>
    uploaded files, passwords, ...
<? } ?>
  </td>
  <td align="right">
    <textarea rows="5" cols="40"><?=$description_sec?></textarea>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    полные базы, полные<br>
    версии программ, ...<br>
<? } else { ?>
    full databases, full<br>
    program versions, ...<br>
<? } ?>
  </td>
  <td align="center">
    <b>
    <? 
    if (empty($files_sec)) {
      echo "---";
    } else {
      foreach($files_sec as $file) {
        echo '<a href="index.php?file='.$file['path'].'&name='.$file['name'].'" target="_blank">'.$file['name'].'</a><br>';
      }
    }
    ?>
    </b>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    цена в BTC
<? } else { ?>
    price in BTC
<? } ?>
  </td>
  <td align="center">
    <b><?=$price?></b><br>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    сделка удалится через<br>
<? } else { ?>
    deal deletes in<br>
<? } ?>
  </td>
  <td align="center">
    <b><?=$delete_in?></b><br>
  </td>
</tr>
<tr><td><br></td></tr>
</table>
</form>
</body>
</html>
  <?
}




/* =-=-=-=-=-=-=-=-=-=-=-=  управление сделкой - покупатель  =-=-=-=-=-=-=-=-=-=-=-= */
if (!empty($buyer)) {
  $url_buyer = $buyer;
  $sql = 'SELECT * FROM `deals` WHERE `url_buyer` = "'.$url_buyer.'"';
  $result = sql($sql);
  $deal_info = mysqli_fetch_assoc($result);
  if (empty($deal_info)) {
    $report = array();
    $report[] = "[[SERVER]]";
    foreach ($_SERVER as $key=>$value) $report[] = "$key = $value";
    $report[] = "[[REQUEST]]";
    foreach ($_REQUEST as $key=>$value) $report[] = "$key = $value";
    notify('HACKING ATTEMPT! line '.__LINE__.' DATA: '.implode(' | ',$report));
    die();
  }
  $deal_id = $deal_info['id'];
  $status = $deal_info['status'];
  $description_pub = htmlspecialchars(base64_decode($deal_info['description_pub']));
  $description_sec = htmlspecialchars(base64_decode($deal_info['description_sec']));
  $files_pub = json_decode(base64_decode($deal_info['files_pub']), true);
  $files_sec = json_decode(base64_decode($deal_info['files_sec']), true);
  $price = rtrim($deal_info['price'],0);
  $btc = $deal_info['btc'];
  $code_buyer = $deal_info['code_buyer'];
  $url_dispute = $deal_info['url_dispute'];
  $store_days = $deal_info['store_days'];
  $changed = $deal_info['changed'];
  $timeout = ($store_days * 24 * 60 * 60) - (time() - $changed);
  if ($timeout < 0 and $status != 'dispute') {
    foreach ($files_pub as $id=>$file) {
      $dir = 'uploads/'.substr($file['path'], 0, 2);
      @unlink($dir.'/'.$file['path']);
    }
    foreach ($files_sec as $id=>$file) {
      $dir = 'uploads/'.substr($file['path'], 0, 2);
      @unlink($dir.'/'.$file['path']);
    }
    /* удаляем из бд */
    $sql = 'DELETE FROM `deals` WHERE `id` = "'.$deal_id.'"';
    $result = sql($sql);
    if ($result == true) {
      die();
    }
  } 
  $delete_in_d = floor($timeout / (60 * 60 * 24));
  $timeout -= $delete_in_d * (60 * 60 * 24);
  $delete_in_h = floor($timeout / (60 * 60));
  $timeout -= $delete_in_h * (60 * 60);
  $delete_in_m = floor($timeout / 60);
  $timeout -= $delete_in_m * 60;
  $delete_in_s = floor($timeout);
  $timeout -= $delete_in_s;
  $delete_in = "{$delete_in_d}d {$delete_in_h}h {$delete_in_m}m {$delete_in_s}s";


  /* если конфирмед - генерируем кошель и новый урл (чтобы ушлый селлер не подтверждал выполнение сделки) и заливаем в базу */
  if ($status == 'new' and !empty($_REQUEST['CONFIRM'])) {
    $newurl = gen();
    $code_buyer = gen(3);
    $btc = gen_btc($deal_id);
    if (empty($btc)) {
      notify('FAILED TO GENERATE BITCOIN ADDRESS! line '.__LINE__);
      die('failed to generate Bitcoin address! contact admin');
    }
    $sql = 'UPDATE `deals` SET `status` = "processing", `changed` = UNIX_TIMESTAMP(NOW()), `url_buyer` = "'.$newurl.'", `code_buyer` = "'.$code_buyer.'", `btc` = "'.$btc.'" WHERE `url_buyer` = "'.$url_buyer.'";';
    $result = sql($sql);
    if ($result == true) {
      notify('NEW DEAL: http://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME']).'/index.php?buyer='.$newurl); #DEBUG
      header('Location: index.php?buyer='.$newurl);
    }
  }

  /* если юзер передумал оплачивать */
  if ($status == 'processing' and !empty($_REQUEST['DECLINE'])) {
    $delete_code = esc($_POST['code_1']).esc($_POST['code_2']).esc($_POST['code_3']);
    if ($delete_code != $code_buyer) {
      if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') {
        $error .= "неправильный код!<br>";
      } else {
        $error .= "wrong code!<br>";
      }
    } else {
      $sql = 'UPDATE `deals` SET `status` = "new", `changed` = UNIX_TIMESTAMP(NOW()) WHERE `id` = "'.$deal_id.'"';
      $result = sql($sql);
      if ($result == true) {
        header('Location: index.php?buyer='.$url_buyer);
      }
    }
  }

  if (!empty($_REQUEST['chat']) and !empty($_REQUEST['message'])) {
    if (mb_strlen($_REQUEST['message']) > 200) {
      $error .= "message > 200 symbols!<br>";
    } else {
      $message = base64_encode($_REQUEST['message']);
      $sql = 'INSERT INTO `chat` (`deal_id`, `author`, `unixtime`, `message`) VALUES ("'.$deal_id.'", "buyer", UNIX_TIMESTAMP(NOW()), "'.$message.'")';
      $result = sql($sql);
    }
  }

  if ($status == 'processing') {
    $balance = get_balance($deal_id);
    if ($balance['confirmed'] >= $price) {
      $sql = 'UPDATE `deals` SET `status` = "paid", `changed` = UNIX_TIMESTAMP(NOW()) WHERE `id` = "'.$deal_id.'"';
      $result = sql($sql);
      if ($result == true) {
        header('Location: index.php?buyer='.$url_buyer);
      }
    }
    /* костыль для отображения нулей при пустом балансе */
    @$balance['confirmed'] = (float)$balance['confirmed'];
    @$balance['unconfirmed'] = (float)$balance['unconfirmed'];
  }

  /* запрос на арбитраж */
  if (!empty($_REQUEST['DISPUTE'])) {
    $code_dispute_tosql = gen(3);
    $url_dispute_tosql = gen();
    $sql = 'UPDATE `deals` SET `status` = "dispute", `changed` = UNIX_TIMESTAMP(NOW()), `code_dispute` = "'.$code_dispute_tosql.'", `url_dispute` = "'.$url_dispute_tosql.'" WHERE `id` = "'.$deal_id.'"';
    $result = sql($sql);
    if ($result == true) {
      if ($_SERVER['HTTPS'] == 'on') {
        $link_for_admin = 'https://';
      } else {
        $link_for_admin = 'http://';
      }
      $link_for_admin .= $_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME']).'/index.php?dispute='.$url_dispute_tosql;
      notify("NEW REQUEST FOR ARBITRAGE: $link_for_admin");
      header('Location: index.php?buyer='.$url_buyer);
    }
  }

  /* покупатель доволен */
  if (!empty($_REQUEST['GOOD'])) {
    $confirm_code = esc($_POST['code_1']).esc($_POST['code_2']).esc($_POST['code_3']);
    if ($confirm_code != $code_buyer) {
      if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') {
        $error .= "неправильный код!<br>";
      } else {
        $error .= "wrong code!<br>";
      }
    } else {
      $sql = 'UPDATE `deals` SET `status` = "complete", `changed` = UNIX_TIMESTAMP(NOW()) WHERE `id` = "'.$deal_id.'"';
      $result = sql($sql);
      if ($result == true) {
        header('Location: index.php?buyer='.$url_buyer);
      }
    }
  }

  /* возврат бабла после арбитража */
  if ($status == 'paid' and !empty($_REQUEST['MONEYBACK'])) {
    $confirm_code = esc($_POST['code_1']).esc($_POST['code_2']).esc($_POST['code_3']);
    if ($confirm_code != $code_buyer) {
      if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') {
        $error .= "неправильный код!<br>";
      } else {
        $error .= "wrong code!<br>";
      }
    } else {
      $balance = get_balance($deal_id);
      $address = esc($_POST['address']);
      if (!empty($url_dispute)) {
        /* был диспут, даём % арбитру */
        $minus = ($balance['confirmed'] + $balance['unconfirmed']) * ($dispute_fee / 100);
        $sql = 'INSERT INTO `payouts` (`created`, `owner`, `amount`) VALUES (UNIX_TIMESTAMP(NOW()), "dispute", "'.$minus.'")';
        $result = sql($sql);
      } else {
        $minus = 0;
      }
      if (!empty($service_fee)) {
        $service_payout = ($balance['confirmed'] + $balance['unconfirmed']) * ($service_fee / 100);
        $sql = 'INSERT INTO `payouts` (`created`, `owner`, `amount`) VALUES (UNIX_TIMESTAMP(NOW()), "service", "'.$service_payout.'")';
        $result = sql($sql);
      }
      $amount = round((($balance['confirmed'] + $balance['unconfirmed']) - $btc_fee - ($balance['confirmed'] + $balance['unconfirmed']) * ($service_fee/100) - $minus),8);
      $transaction = send_btc($deal_id,$address,$amount);
      if ($transaction) {
        $sql = 'UPDATE `deals` SET `status` = "payout", `changed` = UNIX_TIMESTAMP(NOW()), `payout` = "'.$address.'" WHERE `id` = "'.$deal_id.'"';
        $result = sql($sql);
        if (!$result) {
          $error .= "failed to update database!<br>";
          notify("failed to run sql '".$sql."'");
        }
        header('Location: index.php?buyer='.$url_buyer);
      } else {
        $error .= "failed to send money!<br>";
      }
    }
  }

  ?>
<!DOCTYPE html>
<html>
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <?
  if ($status == 'new') {
    if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
  <title>Подтверждение сделки</title>
    <? } else { ?>
  <title>Deal confirmation</title>
    <? } 
  }
  if ($status == 'processing') {
    if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
  <title>Оплата сделки</title>
    <? } else { ?>
  <title>Deal payment</title>
    <? }
  }
  if ($status == 'paid') {
    if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
  <title>Получение товара</title>
    <? } else { ?>
  <title>Checking the goods</title>
    <? } 
  }
  if ($status == 'dispute') {
    if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
  <title>Разборки</title>
    <? } else { ?>
  <title>Arbitrage</title>
    <? } 
  }
  if ($status == 'complete') {
    if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
  <title>Завершение сделки</title>
    <? } else { ?>
  <title>Deal completion</title>
    <? }
  }
  ?>
  <style>
  textarea { overflow-x: hidden; }
  </style>
</head>
<body>
<noscript><p style="color:red;text-align:center;">PLEASE ENABLE JAVASCRIPT</p></noscript>
  <?
  if (!empty($error) and (!empty($_REQUEST['CONFIRM']) or !empty($_REQUEST['chat']) or !empty($_REQUEST['GOOD']))) { 
    echo '<br><p style="color:red;text-align:center;"><b>ERROR: '.$error.'</b></p><br>';
  }
  ?>
<form method="POST" action="" enctype="multipart/form-data">
<table border="0" width="900px" align="center">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
<tr><td></td><td style="text-align:center;"><a href="#" onclick="document.cookie='lang=en;';location.reload();">ENGLISH</a><br><br></td></tr>
<? } else { ?>
<tr><td></td><td style="text-align:center;"><a href="#" onclick="document.cookie='lang=ru;';location.reload();">РУССКИЙ</a><br><br></td></tr>
<? } ?>
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    статус сделки
<? } else { ?>
    deal status
<? } ?>
  </td>
  <td align="center">
   <b><?=$status?></b>
  </td>
</tr>
<tr><td><br></td></tr>
  
  <? 
  if ($status != 'new') {
  ?>
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    ваш код подтверждения
<? } else { ?>
    your confirmation code
<? } ?>
  </td>
  <td align="center">
   <b><?=$code_buyer?></b>
  </td>
</tr>
<tr><td><br></td></tr>
  <?
  }

  if ($status == 'processing') {
  ?>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    <span style="font-size:0.8em;">вы можете отказаться от сделки до оплаты.<br>
    для отказа введите ваш код</span>
<? } else { ?>
    <span style="font-size:0.8em;">you can decline the deal before payment.<br>
    enter your code to decline</span>
<? } ?>
  </td>
  <td align="center">
    <input type="text" size="1" maxlength="1" name="code_1" style="width:7%;" />
    <input type="text" size="1" maxlength="1" name="code_2" style="width:7%;" />
    <input type="text" size="1" maxlength="1" name="code_3" style="width:7%;" />
    <br>
    <input type="submit" name="DECLINE" value="DECLINE" onclick="javascript:return confirm('really decline?')" />
  </td>
</tr>
<tr><td><br><br></td></tr>
</table>
</form>

<form method="POST" action="" enctype="multipart/form-data">
<table border="0" width="900px" align="center">
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    отправьте эту сумму
<? } else { ?>
    send this sum
<? } ?>
  </td>
  <td align="center">
   <b><?=$price?></b> BTC
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    на этот адрес
<? } else { ?>
    to this address
<? } ?>
  </td>
  <td align="center">
   <b><?=$btc?></b>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    получено (wait - не подтверждено)
<? } else { ?>
    received (wait - unconfirmed)
<? } ?>
  </td>
  <td align="center">
   <?=$balance['confirmed']?> BTC (wait: <?=$balance['unconfirmed']?>)
  </td>
</tr>
<tr><td><br></td></tr>
  <?
  }

  if ($status == 'paid' or $status == 'dispute' or $status == 'complete' or $status == 'payout') {
  ?>
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    оплата получена на адрес
<? } else { ?>
    payment received to address
<? } ?>
  </td>
  <td align="center">
   <a href="https://blockchain.info/address/<?=$btc?>" target="_blank"><?=$btc?></a>
  </td>
</tr>
<tr><td><br></td></tr>
  <?
  }

  if ($status == 'complete') {
  ?>
<tr>
  <td align="left" style="font-size:0.8em;">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    сделка завершена. <br>
    теперь продавец может забрать деньги
<? } else { ?>
    the deal is complete. <br>
    seller could take the money now<br>
<? } ?>
  </td>
  <td align="right">
    <hr>
  </td>
</tr>
<tr><td><br><br></td></tr>
  <?
  }

  if ($status == 'dispute') {
  ?>
<tr>
  <td align="left" style="font-size:0.8em;">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    <b>вызван админ для разборок.<br>
    ждите результатов проверки<br>
    вы можете пользоваться чатом</b><br>
<? } else { ?>
    <b>admin was called for arbitrage.<br>
    wait for verification results<br>
    you could use the chat<br>
<? } ?>
  </td>
  <td align="right">
    <hr>
  </td>
</tr>
  <?
  }
  ?>

  <?
  /* арбитр решил вопрос в сторону покупателя */
  if ($status == 'paid' and !empty($url_dispute)) {
  ?>
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    арбитраж решён в вашу пользу<br>
    введите ваш кошелёк Bitcoin<br>
    и код для возврата денег
<? } else { ?>
    arbitrage resolved to your side<br>
    enter your Bitcoin address<br>
    and the code for moneyback
<? } ?>
  </td>
  <td align="center">
    <input type="text" size="1" maxlength="1" name="code_1" style="width:5%;" />
    <input type="text" size="1" maxlength="1" name="code_2" style="width:5%;" />
    <input type="text" size="1" maxlength="1" name="code_3" style="width:5%;" />
    <br>
    <input type="text" size="34" maxlength="35" name="address" id="address" />
    <br>
    <input type="submit" name="MONEYBACK" value="MONEYBACK" onclick="javascript:return confirm('confirm ' + document.getElementById('address').value)"  />
  </td>
</tr>
<tr><td><br><br></td></tr>
  <?
  }
  ?>

<tr>
  <td align="center">
    <input type="submit" name="REFRESH" value="REFRESH" />
  </td>
</tr>

<tr><td><br><br></td></tr>

</table>
</form>

<hr>

<form method="POST" action="" enctype="multipart/form-data">
<script type="text/javascript"> 
function calc_limit() {
  var max = 200;
  var text = document.getElementById('message').value;
  var len = document.getElementById('message').value.length;
  var limit = (max - len);
  document.getElementById('message_limit').innerHTML = limit;
  if (len > max) {
    text = text.substring(0,max);
    document.getElementById('message').value = text;
  }
}
</script>
<table border="0" width="900px" align="center">
<tr><td><br></td></tr>

  <? /* чятик */
  $sql = 'SELECT `author`,`unixtime`,`message` FROM `chat` INNER JOIN `deals` ON `deals`.`id` = `chat`.`deal_id` WHERE `deals`.`id` = "'.$deal_id.'" ORDER BY `unixtime` ASC';
  $result = sql($sql);
  ?>
  <tr align="center">
    <td style="width:33%;"></td>
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    <td style="width:33%;"><b>Чат</b></td>
<? } else { ?>
    <td style="width:33%;"><b>Chat</b></td>
<? } ?>
    <td style="width:33%;"></td>
  </tr>
  <tr><td><br></td></tr>
  <?
  if (mysqli_num_rows($result) > 0) {
  ?>
  <tr align="center">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    <td style="width:33%;">- Время -</td>
    <td style="width:33%;">- Продавец -</td>
    <td style="width:33%;">- Покупатель -</td>
<? } else { ?>
    <td style="width:33%;">- Time -</td>
    <td style="width:33%;">- Seller -</td>
    <td style="width:33%;">- Buyer -</td>
<? } ?>
  </tr>
  <?
  }
  foreach ($result as $chat) {
    $chat_author = $chat['author'];
    $chat_time = date("Y-m-d H:i:s",$chat['unixtime']);
    $chat_message = htmlentities(base64_decode($chat['message']));
    switch ($chat_author) {
      case 'seller':
        echo "<tr><td>$chat_time</td> <td><textarea rows='2' cols='40'>$chat_message</textarea></td> <td></td></tr>";
        break;
      case 'buyer':
        echo "<tr><td>$chat_time</td> <td></td> <td><textarea rows='2' cols='40'>$chat_message</textarea></td></tr>";
        break;
      case 'dispute':
        echo "<tr><td>$chat_time</td> <td><span style='color:red;'><b>ADMIN COMMENT:</b></span></td> <td><textarea rows='2' cols='40' style='color:red;'>$chat_message</textarea></td>";
        break;
    }
  }
  ?>
<tr><td><br></td></tr>

<tr>
  <td><span style="color:grey;">(max. 200 symbols)</span></td>
  <td align="center" style="display:inline-block;vertical-align:middle;">
    <textarea rows="2" cols="34" name="message" id="message" onKeyDown="calc_limit();" onKeyUp="calc_limit();" onKeyPress="calc_limit();"></textarea><span id="message_limit" name="message_limit" style="color:grey;">200</span>
  </td>
  <td><input type="submit" name="chat" value="send" onclick="javascript:return confirm('send message?');" /></td>
</tr>

<tr><td><br><br></td></tr>

</table>
</form>

<hr> 

<form method="POST" action="" enctype="multipart/form-data">
<table border="0" width="900px" align="center">

<tr><td><br><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    описание товара
<? } else { ?>
    item description
<? } ?>
  </td>
  <td align="right">
    <textarea rows="5" cols="40"><?=$description_pub?></textarea>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    скриншоты, демо программы, <br>
    тестовые выборки баз, ... 
<? } else { ?>
    screenshots, database examples, 
    program trial version, ...
<? } ?>
  </td>
  <td align="center">
    <b>
    <? 
    if (empty($files_pub)) {
      echo "---";
    } else {
      foreach($files_pub as $file) {
        echo '<a href="index.php?file='.$file['path'].'&name='.$file['name'].'" target="_blank">'.$file['name'].'</a><br>';
      }
    }
    ?>
    </b>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    цена в BTC
<? } else { ?>
    price in BTC
<? } ?>
  </td>
  <td align="center">
    <b><?=$price?></b><br>
  </td>
</tr>
<tr><td><br></td></tr>

  <? 
  if ($status == 'paid' or $status == 'dispute' or $status == 'complete' or $status == 'payout') {
  ?>
<tr>
  <td align="left"><b>
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    секретное описание<br>
    видно после оплаты
<? } else { ?>
    secret description<br>
    available after payment
<? } ?>
  </b></td>
  <td align="right">
    <hr><br>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    полное описание, ссылки на <br>
    залитые файлы, пароли, ... 
<? } else { ?>
    full description, links to<br>
    uploaded files, passwords, ...
<? } ?>
  </td>
  <td align="right">
    <textarea rows="5" cols="40"><?=$description_sec?></textarea>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    полные базы, полные<br>
    версии программ, ...
<? } else { ?>
    full databases, full<br>
    program versions, ...
<? } ?>
  </td>
  <td align="center">
    <b>
    <? 
    if (empty($files_sec)) {
      echo "---";
    } else {
      foreach($files_sec as $file) {
        echo '<a href="index.php?file='.$file['path'].'&name='.$file['name'].'" target="_blank">'.$file['name'].'</a><br>';
      }
    }
    ?>
    </b>
  </td>
</tr>
<tr><td><br></td></tr>
  <?
  }
  ?>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    сделка удалится через
<? } else { ?>
    deal deletes in
<? } ?>
  </td>
  <td align="center">
    <b><?=$delete_in?></b>
  </td>
</tr>
<tr><td><br></td></tr>

  <?
  if ($status == 'new' and empty($url_dispute)) {
  ?>
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    если вас устраивают условия подтвердите принятие сделки
<? } else { ?>
    if you are satisfied with conditions then confirm deal acceptance
<? } ?>
  </td>
  <td align="center">
    <input type="submit" name="CONFIRM" value="CONFIRM" onclick="javascript:return confirm('really confirm?')" />
  </td>
</tr>
<tr><td><br></td></tr>
  <?
  }

  if ($status == 'paid') {
  ?>
<tr>
  <td align="left"> 
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    если вас устраивает сделка вы можете разрешить выдачу денег селлеру<br>
    введите ваш код для подтверждения 
<? } else { ?>
    if you are satisfied with the deal you could release money to the seller<br>
    enter your code for confirmation
<? } ?>
  </td>
  <td align="center">
    <input type="text" size="1" maxlength="1" name="code_1" style="width:5%;" />
    <input type="text" size="1" maxlength="1" name="code_2" style="width:5%;" />
    <input type="text" size="1" maxlength="1" name="code_3" style="width:5%;" />
    <br>
    <input type="submit" name="GOOD" value="GOOD" onclick="javascript:return confirm('really good?')" />
  </td>
</tr>
<tr><td><br></td></tr>
  <?
  }
  
  if (($status == 'paid' or $status == 'complete') 
  /* решение арбитра окончательное и обжалованию не подлежит */
  and empty($url_dispute)) { 
  ?>
<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    если вас не устраивает сделка вы можете вызвать админа для разборок
<? } else { ?>
    if you are not satisfied with the deal you could call the admin for arbitrage
<? } ?>
  </td>
  <td align="center">
    <input type="submit" name="DISPUTE" value="DISPUTE" onclick="javascript:return confirm('really dispute?')" />
  </td>
</tr>
<tr><td><br></td></tr>
  <?
  }
  ?>


</table>
</form>
</body>
</html>
  <?
}




/* =-=-=-=-=-=-=-=-=-=-=-=  управление сделкой - арбитраж  =-=-=-=-=-=-=-=-=-=-=-=-= */
if (!empty($dispute)) {
  $url_dispute = $dispute;
  $sql = 'SELECT * FROM `deals` WHERE `url_dispute` = "'.$url_dispute.'"';
  $result = sql($sql);
  $deal_info = mysqli_fetch_assoc($result);
  if (empty($deal_info)) {
    $report = array();
    $report[] = "[[SERVER]]";
    foreach ($_SERVER as $key=>$value) $report[] = "$key = $value";
    $report[] = "[[REQUEST]]";
    foreach ($_REQUEST as $key=>$value) $report[] = "$key = $value";
    notify('HACKING ATTEMPT! line '.__LINE__.' DATA: '.implode(' | ',$report));
    die();
  }
  $deal_id = $deal_info['id'];
  $status = $deal_info['status'];
  $description_pub = htmlspecialchars(base64_decode($deal_info['description_pub']));
  $description_sec = htmlspecialchars(base64_decode($deal_info['description_sec']));
  $files_pub = json_decode(base64_decode($deal_info['files_pub']), true);
  $files_sec = json_decode(base64_decode($deal_info['files_sec']), true);
  $price = rtrim($deal_info['price'],0);
  $btc = $deal_info['btc'];
  $code_dispute = $deal_info['code_dispute'];
  $store_days = $deal_info['store_days'];
  $add_days = (empty($_POST['add_days'])) ? '3' : (int)$_POST['add_days'];
  $changed = $deal_info['changed'];
  $timeout = ($store_days * 24 * 60 * 60) - (time() - $changed);
  if ($timeout < 0 and $status != 'dispute') {
    foreach ($files_pub as $id=>$file) {
      $dir = 'uploads/'.substr($file['path'], 0, 2);
      @unlink($dir.'/'.$file['path']);
    }
    foreach ($files_sec as $id=>$file) {
      $dir = 'uploads/'.substr($file['path'], 0, 2);
      @unlink($dir.'/'.$file['path']);
    }
    /* удаляем из бд */
    $sql = 'DELETE FROM `deals` WHERE `id` = "'.$deal_id.'"';
    $result = sql($sql);
    if ($result == true) {
      die();
    }
  } 
  $delete_in_d = floor($timeout / (60 * 60 * 24));
  $timeout -= $delete_in_d * (60 * 60 * 24);
  $delete_in_h = floor($timeout / (60 * 60));
  $timeout -= $delete_in_h * (60 * 60);
  $delete_in_m = floor($timeout / 60);
  $timeout -= $delete_in_m * 60;
  $delete_in_s = floor($timeout);
  $timeout -= $delete_in_s;
  $delete_in = "{$delete_in_d}d {$delete_in_h}h {$delete_in_m}m {$delete_in_s}s";

  /* если покупатель мудак - выдать бабки селлеру */
  if (!empty($_REQUEST['RESOLVE_SELLER'])) {
    $confirm_code = esc($_POST['code_1']).esc($_POST['code_2']).esc($_POST['code_3']);
    if ($confirm_code != $code_dispute) {
      if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') {
        $error .= "неправильный код!<br>";
      } else {
        $error .= "wrong code!<br>";
      }
    } else {
      $sql = 'UPDATE `deals` SET `status` = "complete", `changed` = UNIX_TIMESTAMP(NOW()) WHERE `id` = "'.$deal_id.'"';
      $result = sql($sql);
      if ($result == true) {
        header('Location: index.php?dispute='.$url_dispute);
      }
    }
  }
  
  /* если продавец мудак - выдать бабки покупателю */
  if (!empty($_REQUEST['RESOLVE_BUYER'])) {
    $confirm_code = esc($_POST['code_1']).esc($_POST['code_2']).esc($_POST['code_3']);
    if ($confirm_code != $code_dispute) {
      if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') {
        $error .= "неправильный код!<br>";
      } else { 
        $error .= "wrong code!<br>";
      }
    } else {
      $sql = 'UPDATE `deals` SET `status` = "new", `changed` = UNIX_TIMESTAMP(NOW()) WHERE `id` = "'.$deal_id.'"';
      $result = sql($sql);
      if ($result == true) {
        header('Location: index.php?dispute='.$url_dispute);
      }
    }
  }

  /* добавление времени к таймауту */
  if (!empty($_REQUEST['ADD_DAYS'])) {
    $confirm_code = esc($_POST['code_1']).esc($_POST['code_2']).esc($_POST['code_3']);
    if ($confirm_code != $code_dispute) {
      if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') {
        $error .= "неправильный код!<br>";
      } else {
        $error .= "wrong code!<br>";
      }
    } else {
      $sql = 'UPDATE `deals` SET `store_days` = `store_days` + "'.$add_days.'" WHERE `id` = "'.$deal_id.'"';
      if (sql($sql)) {
        header('Location: index.php?dispute='.$url_dispute);
      }
    }
  }

  if (!empty($_REQUEST['chat']) and !empty($_REQUEST['message'])) {
    if (mb_strlen($_REQUEST['message']) > 200) {
      $error .= "message > 200 symbols!<br>";
    } else {
      $message = base64_encode($_REQUEST['message']);
      $sql = 'INSERT INTO `chat` (`deal_id`, `author`, `unixtime`, `message`) VALUES ("'.$deal_id.'", "dispute", UNIX_TIMESTAMP(NOW()), "'.$message.'")';
      $result = sql($sql);
    }
  }

  ?>
<!DOCTYPE html>
<html>
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
  <title>Арбитраж</title>
<? } else { ?>
  <title>Arbitrage</title>
<? } ?>
  <style>
  textarea { overflow-x: hidden; }
  </style>
</head>
<body>
<noscript><p style="color:red;text-align:center;">PLEASE ENABLE JAVASCRIPT</p></noscript>
  <?
  if (!empty($error) and (!empty($_REQUEST['DELETE']) or !empty($_REQUEST['chat']) or !empty($_REQUEST['RESOLVE_SELLER']) or !empty($_REQUEST['RESOLVE_BUYER']) or !empty($_REQUEST['add_days']))) { 
    echo '<br><p style="color:red;text-align:center;"><b>ERROR: '.$error.'</b></p><br>';
  }
  ?>
<form method="POST" action="" enctype="multipart/form-data">
<table border="0" width="900px" align="center">

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    статус сделки
<? } else { ?>
    deal status
<? } ?>
  </td>
  <td align="center">
   <b><?=$status?></b>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    ваш код подтверждения
<? } else { ?>
    your confirmation code
<? } ?>
  </td>
  <td align="center">
   <b><?=$code_dispute?></b>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    адрес для оплаты
<? } else { ?>
    payment address
<? } ?>
  </td>
  <td align="center">
   <a href="https://blockchain.info/address/<?=$btc?>" target="_blank"><?=$btc?></a>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    для продолжения введите ваш код
<? } else { ?>
    enter your code to continue
<? } ?>
  </td>
  <td align="center">
    <input type="text" size="1" maxlength="1" name="code_1" style="width:5%;" />
    <input type="text" size="1" maxlength="1" name="code_2" style="width:5%;" />
    <input type="text" size="1" maxlength="1" name="code_3" style="width:5%;" />
  </td>
</tr>
<tr><td><br><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    решить вопрос в сторону продавца<br>
    статус сделки станет 'complete'<br>
    и продавец сможет забрать деньги
<? } else { ?>
    resolve the issue to seller side,<br>
    deal status will become 'complete'<br>
    and seller will be able to get money
<? } ?>
  </td>
  <td align="center">
    <input type="submit" name="RESOLVE_SELLER" value="RESOLVE_SELLER" onclick="javascript:return confirm('buyer mudak?')" />
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    решить вопрос в сторону покупателя<br>
    статус сделки станет 'new'<br>
    и покупатель сможет забрать деньги
<? } else { ?>
    resolve the issue to buyer side,<br>
    deal status will become 'new'<br>
    and buyer will be able to get moneyback
<? } ?>
  </td>
  <td align="center">
    <input type="submit" name="RESOLVE_BUYER" value="RESOLVE_BUYER" onclick="javascript:return confirm('seller mudak?')" />
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    добавить дней к таймауту
<? } else { ?>
    add days to timeout
<? } ?>
  </td>
  <td align="right">
    1<input type="radio" name="add_days" value="1" <?=($add_days == '1') ? 'checked' : ''?>>
    3<input type="radio" name="add_days" value="3" <?=($add_days == '3') ? 'checked' : ''?>>
    7<input type="radio" name="add_days" value="7" <?=($add_days == '7') ? 'checked' : ''?>> <br>
    14<input type="radio" name="add_days" value="14" <?=($add_days == '14') ? 'checked' : ''?>>
    30<input type="radio" name="add_days" value="30" <?=($add_days == '30') ? 'checked' : ''?>> <br><br>
    <input type="submit" name="ADD_DAYS" value="ADD DAYS" onclick="javascript:return confirm('add timeout?')" />
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="center">
    <input type="submit" name="REFRESH" value="REFRESH" />
  </td>
</tr>

<tr><td><br></td></tr>
</table>
</form>

<hr>

<form method="POST" action="" enctype="multipart/form-data">
<script type="text/javascript"> 
function calc_limit() {
  var max = 200;
  var text = document.getElementById('message').value;
  var len = document.getElementById('message').value.length;
  var limit = (max - len);
  document.getElementById('message_limit').innerHTML = limit;
  if (len > max) {
    text = text.substring(0,max);
    document.getElementById('message').value = text;
  }
}
</script>
<table border="0" width="900px" align="center">
<tr><td><br></td></tr>

  <? /* чятик */
  $sql = 'SELECT `author`,`unixtime`,`message` FROM `chat` INNER JOIN `deals` ON `deals`.`id` = `chat`.`deal_id` WHERE `deals`.`id` = "'.$deal_id.'" ORDER BY `unixtime` ASC';
  $result = sql($sql);
  ?>
  <tr align="center">
    <td style="width:33%;"></td>
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    <td style="width:33%;"><b>Чат</b></td>
<? } else { ?>
    <td style="width:33%;"><b>Chat</b></td>
<? } ?>
    <td style="width:33%;"></td>
  </tr>
  <tr><td><br></td></tr>
  <? 
  if (mysqli_num_rows($result) > 0) {
  ?>
  <tr align="center">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    <td style="width:33%;">- Время -</td>
    <td style="width:33%;">- Продавец -</td>
    <td style="width:33%;">- Покупатель -</td>
<? } else { ?>
    <td style="width:33%;">- Time -</td>
    <td style="width:33%;">- Seller -</td>
    <td style="width:33%;">- Buyer -</td>
<? } ?>
  </tr>
  <?
  }
  foreach ($result as $chat) {
    $chat_author = $chat['author'];
    $chat_time = date("Y-m-d H:i:s",$chat['unixtime']);
    $chat_message = htmlentities(base64_decode($chat['message']));
    switch ($chat_author) {
      case 'seller':
        echo "<tr><td>$chat_time</td> <td><textarea rows='2' cols='40'>$chat_message</textarea></td> <td></td></tr>";
        break;
      case 'buyer':
        echo "<tr><td>$chat_time</td> <td></td> <td><textarea rows='2' cols='40'>$chat_message</textarea></td></tr>";
        break;
      case 'dispute':
        echo "<tr><td>$chat_time</td> <td><span style='color:red;'><b>ADMIN COMMENT:</b></span></td> <td><textarea rows='2' cols='40' style='color:red;'>$chat_message</textarea></td>";
        break;
    }
  }
  ?>
<tr><td><br></td></tr>

<tr>
  <td><span style="color:grey;">(max. 200 symbols)</span></td>
  <td align="center" style="display:inline-block;vertical-align:middle;">
    <textarea rows="2" cols="34" name="message" id="message" onKeyDown="calc_limit();" onKeyUp="calc_limit();" onKeyPress="calc_limit();"></textarea><span id="message_limit" name="message_limit" style="color:grey;">200</span>
  </td>
  <td><input type="submit" name="chat" value="send" onclick="javascript:return confirm('send message?');" /></td>
</tr>

<tr><td><br><br></td></tr>

</table>
</form>

<hr>

<form method="POST" action="" enctype="multipart/form-data">
<table border="0" width="900px" align="center">

<tr>
  <td align="left"><b>
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    публичное описание<br>
    видно до оплаты
<? } else { ?>
    public description<br>
    available before payment
<? } ?>
  </b></td>
  <td align="right">
    <hr><br>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    описание товара
<? } else { ?>
    item description
<? } ?>
  </td>
  <td align="right">
    <textarea rows="5" cols="40"><?=$description_pub?></textarea>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    скриншоты, демо программы, <br>
    тестовые выборки баз, ... 
<? } else { ?>
    screenshots, database examples, <br>
    program trial version, ...
<? } ?>
  </td>
  <td align="center">
    <b>
    <? 
    if (empty($files_pub)) {
      echo "---";
    } else {
      foreach($files_pub as $file) {
        echo '<a href="index.php?file='.$file['path'].'&name='.$file['name'].'" target="_blank">'.$file['name'].'</a><br>';
      }
    }
    ?>
    </b>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left"><b>
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    секретное описание<br>
    видно после оплаты
<? } else { ?>
    secret description<br>
    available after payment
<? } ?>
  </b></td>
  <td align="right">
    <hr><br>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    полное описание, ссылки на<br>
    залитые файлы, пароли, ...
<? } else { ?>
    full description, links to<br>
    uploaded files, passwords, ...
<? } ?>
  </td>
  <td align="right">
    <textarea rows="5" cols="40"><?=$description_sec?></textarea>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    полные базы, полные<br>
    версии программ, ...
<? } else { ?>
    full databases, full<br>
    program versions, ...
<? } ?>
  </td>
  <td align="center">
    <b>
    <? 
    if (empty($files_sec)) {
      echo "---";
    } else {
      foreach($files_sec as $file) {
        echo '<a href="index.php?file='.$file['path'].'&name='.$file['name'].'" target="_blank">'.$file['name'].'</a><br>';
      }
    }
    ?>
    </b>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    цена в BTC
<? } else { ?>
    price in BTC
<? } ?>
  </td>
  <td align="center">
    <b><?=$price?></b><br>
  </td>
</tr>
<tr><td><br></td></tr>

<tr>
  <td align="left">
<? if (empty($_COOKIE['lang']) or $_COOKIE['lang'] == 'ru') { ?>
    сделка удалится через
<? } else { ?>
    deal deletes in
<? } ?>
  </td>
  <td align="center">
    <b><?=$delete_in?></b><br>
  </td>
</tr>
<tr><td><br></td></tr>
</table>
</form>
</body>
</html>
  <?
}



/* ========================================================================= */

function notify($message) {
  /* TODO: подгружать файл xmpphp и отправлять сообщение пыхом, чтобы не использовать вызов системных команд и всякие escapeshellarg */
  global $admin;
  $cmd = "nohup php xmpphp.php '".$admin."' ".escapeshellarg($message)." >/dev/null 2>/dev/null &";
  exec($cmd);
  return true;
}

function esc($string) {
  global $mysqli;
  return mysqli_real_escape_string($mysqli, $string);
}

function sql($string) {
  global $mysqli;
  $result = mysqli_query($mysqli, $string);
  if (!$result) {
#    die('failed to run sql: [' . $string . '] error: [' . mysqli_error($mysqli) . ']'); #DEBUG
    notify('SQL ERROR [' . $string .'] message: '. mysqli_error($mysqli)); #RELEASE
    die(); #RELEASE
  } else {
    return $result;
  }
}

function gen($length = 40) {
  return substr(sha1(mt_rand(666,1337).time()), 0, $length);
}

function gen_btc($deal_id) {
  global $btc_user,$btc_pass,$btc_host,$btc_port,$btc_protocol;
  $bitcoin = new jsonRPCClient($btc_protocol.'://'.$btc_user.':'.$btc_pass.'@'.$btc_host.':'.$btc_port);
  $address = $bitcoin->getnewaddress('deal_'.$deal_id);
  if (!is_array($address)) {
    return $address;
  } else {
    return false;
  }
}

function get_balance($deal_id) {
  global $btc_user,$btc_pass,$btc_host,$btc_port,$btc_protocol;
  $bitcoin = new jsonRPCClient($btc_protocol.'://'.$btc_user.':'.$btc_pass.'@'.$btc_host.':'.$btc_port);
  $balance = array('confirmed' => 0.0, 'unconfirmed' => 0.0);
  $transactions = $bitcoin->listtransactions('deal_'.$deal_id,100);
  if (is_array($transactions) and empty($transactions['error'])) {
    foreach ($transactions as $trans) {
      if ($trans['confirmations'] > 0) {
        $balance['confirmed'] = $balance['confirmed'] + $trans['amount'];
      } else {
        $balance['unconfirmed'] = $balance['unconfirmed'] + $trans['amount'];
      }
    }
    return $balance;
  } else {
    return false;
  }
}

function send_btc($deal_id,$address,$amount,$passphrase='') {
  /* TODO: когда-нибудь, в далёком будущем, поправить этот пиздец */
  global $btc_fee,$btc_user,$btc_pass,$btc_host,$btc_port,$btc_protocol;
  if (empty($deal_id) or empty($address)) die();
  $bitcoin = new jsonRPCClient($btc_protocol.'://'.$btc_user.':'.$btc_pass.'@'.$btc_host.':'.$btc_port);
  if (!empty($passphrase)) {
    if(!$bitcoin->walletpassphrase($passphrase, 20)) {
      notify('FAILED TO DECRYPT WALLET! line '.__LINE__);
      die();
    }
  }
  $settxfee = $bitcoin->settxfee($btc_fee);
  $transaction_id = $bitcoin->sendfrom('deal_'.$deal_id, $address, (float)$amount);
  if (!is_array($transaction_id)) {
    return $transaction_id;
  } else {
    /* требуется комиссия больше, чем $btc_fee */
    if (strpos($transaction_id['error']['message'],'fee of at least') !== false) {
      preg_match("/\d\.\d*/",$transaction_id['error']['message'],$match);
      $btc_fee = (float)$match[0];
      $btc_fee = ceil($btc_fee * 10000) / 10000; /* округляем вверх */
      $amount = $amount - $btc_fee;
      $settxfee = $bitcoin->settxfee($btc_fee);
      $transaction_id = $bitcoin->sendfrom('deal_'.$deal_id, $address, (float)$amount);
      if (!is_array($transaction_id)) {
        /* это скорее всего не произойдёт */
        return $transaction_id; 
      } else {
        /* потому что нужна уже новая комиссия, с учётом новой суммы */
        if (strpos($transaction_id['error']['message'],'fee of at least') !== false) {
          preg_match("/\d\.\d*/",$transaction_id['error']['message'],$match);
          $btc_fee = (float)$match[0];
          $btc_fee = ceil($btc_fee * 10000) / 10000; /* округляем вверх */
          $amount = $amount - $btc_fee;
          $settxfee = $bitcoin->settxfee($btc_fee);
          $transaction_id = $bitcoin->sendfrom('deal_'.$deal_id, $address, (float)$amount);
          if (!is_array($transaction_id)) {
            return $transaction_id;
          } else {
            return false;
          }
        }
      }
    }
  }
}

/* jsonRPCClient.php Copyright 2007 Sergio Vaccaro <sergio@inservibile.org> */
class jsonRPCClient {
    private $url;
    private $id;
    public function __construct($url) {
        $this->url = $url;
        $this->id = 1;
    }
    public function __call($method,$params) {
        if (!is_scalar($method)) {
            throw new Exception('Method name has no scalar value');
        }
        if (is_array($params)) {
            $params = array_values($params);
        } else {
            throw new Exception('Params must be given as array');
        }
        $currentId = $this->id;
        $request = array(
            'method' => $method,
            'params' => $params,
            'id' => $currentId
        );
        $request = json_encode($request);
        $opts = array ('http' => array (
            'method'  => 'POST',
            'header'  => 'Content-type: application/json',
            'ignore_errors' => true, #DEBUG
            'content' => $request),

            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        $context  = stream_context_create($opts);
        if ($fp = fopen($this->url, 'r', false, $context)) {
            $response = '';
            while($row = fgets($fp)) {
                $response.= trim($row)."\n";
            }
            $response = json_decode($response,true);
        } else {
            return $response;
        }
        if ($response['id'] != $currentId) {
            return $response;
        }
        if (!is_null($response['error'])) {
            return $response;
        }
        return $response['result'];
    }
}
