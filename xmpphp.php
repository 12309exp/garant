<?php /* 20160403 */
die('CHANGE BOT LOGIN-PASSWORD');

if (php_sapi_name() == 'cli') {
  if (!empty($argv[1]) and !empty($argv[2])) { 
    $contact = $argv[1];
    $message = $argv[2];
  } else {
    echo "USAGE: php ".$argv[0]." 'contact1,contact2,contact3' 'message here'\n";
    die();
  }
} else {
  die();
}

$jabber_server   = 'exploit.im';
$jabber_port     = 5222;
$jabber_username = 'bot';
$jabber_password = '123123';

sendJabber($contact,$message);

function sendJabber($contact,$message) {
  global $jabber_server,$jabber_port,$jabber_username,$jabber_password;
  $jabber_host = explode('@',$jabber_server); /* getting main domain from connection server */
  $jabber_host=$jabber_host[0];
/* //DEBUG
  $jabber_host=explode('.',$jabber_host); //checking for subdomain
  if (count($jabber_host)>2) { 
    //removing 3rd level domain
    $jabber_host=$jabber_host[1].'.'.$jabber_host[2];
  } else {
    $jabber_host=$jabber_host[0].'.'.$jabber_host[1];
  }
*/
  $conn = new XMPPHP_XMPP($jabber_server, $jabber_port, $jabber_username, $jabber_password, 'ESCROW', $jabber_host);
  $conn->connect();
  $conn->processUntil('session_start');
  if(strpos($contact,',')!==false) {
    $contact = explode(',',$contact);
    foreach ($contact as $contact_user) {
      $contact_user = trim($contact_user);
      if (empty($contact_user)) continue;
      $conn->message($contact_user, $message);
    }
  } else {
    $conn->message($contact, $message);
  }
  $conn->disconnect();
  return true;
}

/* Copyright (C) 2008 Nathanael C. Fritz */
class XMPPHP_XMPP {
  protected $host;
  protected $port;
  protected $xml_depth = 0;
  protected $socket;
  protected $parser;
  protected $buffer;
  public $server;
  public $user;
  protected $password;
  protected $resource;
  protected $fulljid;
  protected $basejid;
  protected $authed = false;
  protected $session_started = false;
  protected $use_encryption = true;
  public $track_presence = true;
  protected $stream_start = '<stream>';
  protected $stream_end = '</stream>';
  protected $disconnected = false;
  protected $sent_disconnect = false;
  protected $until = '';
  protected $until_count = '';
  protected $until_happened = false;
  protected $until_payload = array();
  protected $reconnect = true;
  protected $been_reset = false;
  protected $is_server;
  protected $eventhandlers = array();
  protected $ns_map = array();
  protected $current_ns = array();
  protected $xmlobj = null;
  protected $nshandlers = array();
  protected $xpathhandlers = array();
  protected $idhandlers = array();
  protected $lastid = 0;
  protected $default_ns;
  protected $last_send = 0;
  protected $use_ssl = false;
  protected $reconnectTimeout = 20;


  public function __construct($host, $port, $user, $password, $resource, $server = null) {
    $this->setupParser();
    $this->user  = $user;
    $this->host = $host;
    $this->port = $port;
    $this->password = $password;
    $this->resource = $resource;
    if(!$server) $server = $host;
    $this->basejid = $this->user;# . '@' . $this->host; #DEBUG
    $this->track_presence = true;
    $this->stream_start = '<stream:stream to="' . $server . '" xmlns:stream="http://etherx.jabber.org/streams" xmlns="jabber:client" version="1.0">';
    $this->stream_end   = '</stream:stream>';
    $this->default_ns   = 'jabber:client';
    $this->addXPathHandler('{http://etherx.jabber.org/streams}features', 'features_handler');
    $this->addXPathHandler('{urn:ietf:params:xml:ns:xmpp-sasl}success', 'sasl_success_handler');
    $this->addXPathHandler('{urn:ietf:params:xml:ns:xmpp-sasl}failure', 'sasl_failure_handler');
    $this->addXPathHandler('{urn:ietf:params:xml:ns:xmpp-tls}proceed', 'tls_proceed_handler');
    $this->addXPathHandler('{jabber:client}message', 'message_handler');
    $this->addXPathHandler('{jabber:client}presence', 'presence_handler');
  }

  protected function features_handler($xml) {
    if($xml->hasSub('starttls') and $this->use_encryption) {
      $this->send("<starttls xmlns='urn:ietf:params:xml:ns:xmpp-tls'><required /></starttls>");
    } elseif($xml->hasSub('bind') and $this->authed) {
      $id = $this->getId();
      $this->idhandlers[$id] = array('resource_bind_handler', null);
      $this->send("<iq xmlns=\"jabber:client\" type=\"set\" id=\"$id\"><bind xmlns=\"urn:ietf:params:xml:ns:xmpp-bind\"><resource>{$this->resource}</resource></bind></iq>");
    } else {
      echo date('Y-m-d H:i:s', microtime(1))." Attempting Auth...\n";
      if ($this->password) {
      $this->send("<auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='PLAIN'>" . base64_encode("\x00" . $this->user . "\x00" . $this->password) . "</auth>");
      } else {
                        $this->send("<auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='ANONYMOUS'/>");
      }
    }
  }

  public function reset() {
    $this->xml_depth = 0;
    unset($this->xmlobj);
    $this->xmlobj = array();
    $this->setupParser();
    if(!$this->is_server) {
      $this->send($this->stream_start);
    }
    $this->been_reset = true;
  }

  protected function sasl_success_handler($xml) {
    echo date('Y-m-d H:i:s', microtime(1))." Auth success!\n";
    $this->authed = true;
    $this->reset();
  }

  protected function sasl_failure_handler($xml) {
    echo date('Y-m-d H:i:s', microtime(1))." Auth failed!\n";
    $this->disconnect();
    throw new Exception('Auth failed!');
  }

  protected function tls_proceed_handler($xml) {
    echo date('Y-m-d H:i:s', microtime(1))." Starting TLS encryption\n";
    stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    $this->reset();
  }

  public function message_handler($xml) {
    if(isset($xml->attrs['type'])) {
      $payload['type'] = $xml->attrs['type'];
    } else {
      $payload['type'] = 'chat';
    }
    $payload['from'] = $xml->attrs['from'];
    $payload['body'] = $xml->sub('body')->data;
    $payload['xml'] = $xml;
    echo date('Y-m-d H:i:s', microtime(1))." Message: ".$xml->sub('body')->data."\n";
    $this->event('message', $payload);
  }

  public function presence_handler($xml) {
    $payload['type'] = (isset($xml->attrs['type'])) ? $xml->attrs['type'] : 'available';
    $payload['show'] = (isset($xml->sub('show')->data)) ? $xml->sub('show')->data : $payload['type'];
    $payload['from'] = $xml->attrs['from'];
    $payload['status'] = (isset($xml->sub('status')->data)) ? $xml->sub('status')->data : '';
    $payload['priority'] = (isset($xml->sub('priority')->data)) ? intval($xml->sub('priority')->data) : 0;
    $payload['xml'] = $xml;
    if($this->track_presence) {
      $this->roster->setPresence($payload['from'], $payload['priority'], $payload['show'], $payload['status']);
    }
    echo date('Y-m-d H:i:s', microtime(1))." Presence: ".$payload['from']." [".$payload['show']."] ".$payload['status']."\n";
    if(array_key_exists('type', $xml->attrs) and $xml->attrs['type'] == 'subscribe') {
      if($this->auto_subscribe) {
        $this->send("<presence type='subscribed' to='{$xml->attrs['from']}' from='{$this->fulljid}' />");
        $this->send("<presence type='subscribe' to='{$xml->attrs['from']}' from='{$this->fulljid}' />");
      }
      $this->event('subscription_requested', $payload);
    } elseif(array_key_exists('type', $xml->attrs) and $xml->attrs['type'] == 'subscribed') {
      $this->event('subscription_accepted', $payload);
    } else {
      $this->event('presence', $payload);
    }
  }

  protected function resource_bind_handler($xml) {
    if($xml->attrs['type'] == 'result') {
      echo date('Y-m-d H:i:s', microtime(1))." Bound to " . $xml->sub('bind')->sub('jid')->data . "\n";
      $this->fulljid = $xml->sub('bind')->sub('jid')->data;
      $jidarray = explode('/',$this->fulljid);
      $this->jid = $jidarray[0];
    }
    $id = $this->getId();
    $this->idhandlers[$id] = array('session_start_handler', null);
    $this->send("<iq xmlns='jabber:client' type='set' id='$id'><session xmlns='urn:ietf:params:xml:ns:xmpp-session' /></iq>");
  }

  public function getId() {
    $this->lastid++;
    return $this->lastid;
  }

  protected function session_start_handler($xml) {
    echo date('Y-m-d H:i:s', microtime(1))." Session started\n";
    $this->session_started = true;
    $this->event('session_start');
  }

  public function addXPathHandler($xpath, $pointer, $obj = null) {
    if (preg_match_all("/\(?{[^\}]+}\)?(\/?)[^\/]+/", $xpath, $regs)) {
      $ns_tags = $regs[0];
    } else {
      $ns_tags = array($xpath);
    }
    foreach($ns_tags as $ns_tag) {
      list($l, $r) = explode("}", $ns_tag);
      if ($r != null) {
        $xpart = array(substr($l, 1), $r);
      } else {
        $xpart = array(null, $l);
      }
      $xpath_array[] = $xpart;
    }
    $this->xpathhandlers[] = array($xpath_array, $pointer, $obj);
  }

  public function setupParser() {
    $this->parser = xml_parser_create('UTF-8');
    xml_parser_set_option($this->parser, XML_OPTION_SKIP_WHITE, 1);
    xml_parser_set_option($this->parser, XML_OPTION_TARGET_ENCODING, 'UTF-8');
    xml_set_object($this->parser, $this);
    xml_set_element_handler($this->parser, 'startXML', 'endXML');
    xml_set_character_data_handler($this->parser, 'charXML');
  }

  public function charXML($parser, $data) {
    if(array_key_exists($this->xml_depth, $this->xmlobj)) {
      $this->xmlobj[$this->xml_depth]->data .= $data;
    }
  }

  public function startXML($parser, $name, $attr) {
    if($this->been_reset) {
      $this->been_reset = false;
      $this->xml_depth = 0;
    }
    $this->xml_depth++;
    if(array_key_exists('XMLNS', $attr)) {
      $this->current_ns[$this->xml_depth] = $attr['XMLNS'];
    } else {
      $this->current_ns[$this->xml_depth] = $this->current_ns[$this->xml_depth - 1];
      if(!$this->current_ns[$this->xml_depth]) $this->current_ns[$this->xml_depth] = $this->default_ns;
    }
    $ns = $this->current_ns[$this->xml_depth];
    foreach($attr as $key => $value) {
      if(strstr($key, ":")) {
        $key = explode(':', $key);
        $key = $key[1];
        $this->ns_map[$key] = $value;
      }
    }
    if(!strstr($name, ":") === false)
    {
      $name = explode(':', $name);
      $ns = $this->ns_map[$name[0]];
      $name = $name[1];
    }
    $obj = new XMPPHP_XMLObj($name, $ns, $attr);
    if($this->xml_depth > 1) {
      $this->xmlobj[$this->xml_depth - 1]->subs[] = $obj;
    }
    $this->xmlobj[$this->xml_depth] = $obj;
  }

  public function endXML($parser, $name) {
    if($this->been_reset) {
      $this->been_reset = false;
      $this->xml_depth = 0;
    }
    $this->xml_depth--;
    if($this->xml_depth == 1) {
      foreach($this->xpathhandlers as $handler) {
        if (is_array($this->xmlobj) && array_key_exists(2, $this->xmlobj)) {
          $searchxml = $this->xmlobj[2];
          $nstag = array_shift($handler[0]);
          if (($nstag[0] == null or $searchxml->ns == $nstag[0]) and ($nstag[1] == "*" or $nstag[1] == $searchxml->name)) {
            foreach($handler[0] as $nstag) {
              if ($searchxml !== null and $searchxml->hasSub($nstag[1], $ns=$nstag[0])) {
                $searchxml = $searchxml->sub($nstag[1], $ns=$nstag[0]);
              } else {
                $searchxml = null;
                break;
              }
            }
            if ($searchxml !== null) {
              if($handler[2] === null) $handler[2] = $this;
              /* echo date('Y-m-d H:i:s', microtime(1))." Calling ".$handler[1]."\n"; */
              $tmp_string_php7lol = (string)$handler[1];
              $handler[2]->$tmp_string_php7lol($this->xmlobj[2]);
            }
          }
        }
      }
      foreach($this->nshandlers as $handler) {
        if($handler[4] != 1 and array_key_exists(2, $this->xmlobj) and  $this->xmlobj[2]->hasSub($handler[0])) {
          $searchxml = $this->xmlobj[2]->sub($handler[0]);
        } elseif(is_array($this->xmlobj) and array_key_exists(2, $this->xmlobj)) {
          $searchxml = $this->xmlobj[2];
        }
        if($searchxml !== null and $searchxml->name == $handler[0] and ($searchxml->ns == $handler[1] or (!$handler[1] and $searchxml->ns == $this->default_ns))) {
          if($handler[3] === null) $handler[3] = $this;
          /* echo date('Y-m-d H:i:s', microtime(1))." Calling ".$handler[2]."\n"; */
          $tmp_string_php7lol = (string)$handler[2];
          $handler[3]->$tmp_string_php7lol($this->xmlobj[2]);
        }
      }
      foreach($this->idhandlers as $id => $handler) {
        if(array_key_exists('id', $this->xmlobj[2]->attrs) and $this->xmlobj[2]->attrs['id'] == $id) {
          if($handler[1] === null) $handler[1] = $this;
          $tmp_string_php7lol = (string)$handler[0];
          $handler[1]->$tmp_string_php7lol($this->xmlobj[2]);
          #id handlers are only used once
          unset($this->idhandlers[$id]);
          break;
        }
      }
      if(is_array($this->xmlobj)) {
        $this->xmlobj = array_slice($this->xmlobj, 0, 1);
        if(isset($this->xmlobj[0]) && $this->xmlobj[0] instanceof XMPPHP_XMLObj) {
          $this->xmlobj[0]->subs = null;
        }
      }
      unset($this->xmlobj[2]);
    }
    if($this->xml_depth == 0 and !$this->been_reset) {
      if(!$this->disconnected) {
        if(!$this->sent_disconnect) { $this->send($this->stream_end); }
        $this->disconnected = true;
        $this->sent_disconnect = true;
        @fclose($this->socket);
        if($this->reconnect) { $this->doReconnect(); }
      }
      $this->event('end_stream');
    }
  }

  public function event($name, $payload = null) {
    /* echo date('Y-m-d H:i:s', microtime(1))." EVENT: $name\n"; */
    foreach($this->eventhandlers as $handler) {
      if($name == $handler[0]) {
        if($handler[2] === null) {
          $handler[2] = $this;
        }
        $tmp_string_php7lol = (string)$handler[1];
        $handler[2]->$tmp_string_php7lol($payload);
      }
    }
    foreach($this->until as $key => $until) {
      if(is_array($until)) {
        if(in_array($name, $until)) {
          $this->until_payload[$key][] = array($name, $payload);
          if(!isset($this->until_count[$key])) {
            $this->until_count[$key] = 0;
          }
          $this->until_count[$key] += 1;
        }
      }
    }
  }

  private function __process($maximum=5) {
    $remaining = $maximum;
    do {
      $starttime = (microtime(true) * 1000000);
      $read = array($this->socket);
      $write = array();
      $except = array();
      if (is_null($maximum)) { $secs = NULL; $usecs = NULL; } 
      else if ($maximum == 0) { $secs = 0; $usecs = 0; } 
      else { $usecs = $remaining % 1000000; $secs = floor(($remaining - $usecs) / 1000000); }
      $updated = @stream_select($read, $write, $except, $secs, $usecs);
      if ($updated === false) {
        /* echo date('Y-m-d H:i:s', microtime(1))." Error on stream_select() \n"; */
        if ($this->reconnect) { $this->doReconnect(); } 
        else {
          @fclose($this->socket);
          $this->socket = NULL;
          return false;
        }
      } else if ($updated > 0) {
        $buff = @fread($this->socket, 4096);
        if(!$buff) {
          if($this->reconnect) { $this->doReconnect(); } 
          else {
            @fclose($this->socket);
            $this->socket = NULL;
            return false;
          }
        }
        /* echo date('Y-m-d H:i:s', microtime(1))." RECV: $buff\n"; */
        xml_parse($this->parser, $buff, false);
      } else {
        # $updated == 0 means no changes during timeout.
      }
      $endtime = (microtime(true)*1000000);
      $time_past = $endtime - $starttime;
      $remaining = $remaining - $time_past;
    } while (is_null($maximum) || $remaining > 0);
    return true;
  }

  public function processUntil($event, $timeout=-1) {
    $start = time();
    if(!is_array($event)) $event = array($event);
    $this->until[] = $event;
    end($this->until);
    $event_key = key($this->until);
    reset($this->until);
    $this->until_count[$event_key] = 0;
    $updated = '';
    while(!$this->disconnected and $this->until_count[$event_key] < 1 and (time() - $start < $timeout or $timeout == -1)) {
      $this->__process();
    }
    if(array_key_exists($event_key, $this->until_payload)) {
      $payload = $this->until_payload[$event_key];
      unset($this->until_payload[$event_key]);
      unset($this->until_count[$event_key]);
      unset($this->until[$event_key]);
    } else {
      $payload = array();
    }
    return $payload;
  }

  public function connect($timeout = 30, $sendinit = true) {
    $this->sent_disconnect = false;
    $starttime = time();
    do {
      $this->disconnected = false;
      $this->sent_disconnect = false;
      $conflag = STREAM_CLIENT_CONNECT;
      $conntype = 'tcp';
      if($this->use_ssl) $conntype = 'ssl';
      $context = stream_context_create(array('ssl' => array(
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true
      )));
      echo date('Y-m-d H:i:s', microtime(1))." Connecting to $conntype://".$this->host.":".$this->port."\n";
      try {
        $this->socket = @stream_socket_client("$conntype://{$this->host}:{$this->port}", $errno, $errstr, $timeout, $conflag, $context);
      } catch (Exception $e) {
        #var_dump($e); #DEBUG
        throw new Exception($e->getMessage());
      }
      if(!$this->socket) {
        echo date('Y-m-d H:i:s', microtime(1))." Could not connect\n";
        $this->disconnected = true;
        sleep(5);
      }
    } while (!$this->socket && (time() - $starttime) < $timeout);

    if ($this->socket) {
      stream_set_blocking($this->socket, 1);
      if($sendinit) $this->send($this->stream_start);
    } else {
      throw new Exception("Could not connect before timeout.");
    }
  }

  public function doReconnect() {
    if(!$this->is_server) {
      echo date('Y-m-d H:i:s', microtime(1))." Reconnecting \n";
      $this->connect($this->reconnectTimeout, false);
      $this->reset();
      $this->event('reconnect');
    }
  }

  public function disconnect() {
    echo date('Y-m-d H:i:s', microtime(1))." Disconnecting \n";
    if(false == (bool) $this->socket) {
      return;
    }
    $this->reconnect = false;
    $this->send($this->stream_end);
    $this->sent_disconnect = true;
    $this->processUntil('end_stream', 5);
    $this->disconnected = true;
  }

  public function time() {
    list($usec, $sec) = explode(" ", microtime());
    return (float)$sec + (float)$usec;
  }

  public function send($msg, $timeout=NULL) {
    if (is_null($timeout)) { $secs = NULL; $usecs = NULL; } 
    else if ($timeout == 0) { $secs = 0; $usecs = 0; } 
    else {
      $maximum = $timeout * 1000000;
      $usecs = $maximum % 1000000;
      $secs = floor(($maximum - $usecs) / 1000000);
    }
    $read = array();
    $write = array($this->socket);
    $except = array();
    $select = @stream_select($read, $write, $except, $secs, $usecs);
    if($select === False) {
    echo date('Y-m-d H:i:s', microtime(1))." ERROR sending message; reconnecting.\n";
      $this->doReconnect();
      return false;
    } elseif ($select > 0) {
    /* echo date('Y-m-d H:i:s', microtime(1))." Socket is ready; send it.\n"; */
    } else {
    echo date('Y-m-d H:i:s', microtime(1))." Socket is not ready; break";
      return false;
    }
    $sentbytes = @fwrite($this->socket, $msg);
    /* echo date('Y-m-d H:i:s', microtime(1))." SENT: " . mb_substr($msg, 0, $sentbytes, '8bit')."\n"; */
    if($sentbytes === FALSE) {
    echo date('Y-m-d H:i:s', microtime(1))." ERROR sending message; reconnecting.\n";
      $this->doReconnect();
      return false;
    }
    /* echo date('Y-m-d H:i:s', microtime(1))." Successfully sent $sentbytes bytes\n"; */
    return $sentbytes;
  }

  public function message($to, $body, $type = 'chat', $subject = null, $payload = null) {
    if(is_null($type)) { $type = 'chat'; }
    $to   = htmlspecialchars($to);
    $body = htmlspecialchars($body);
    $subject = htmlspecialchars($subject);
    $out = "<message from=\"{$this->fulljid}\" to=\"$to\" type='$type'>";
    if($subject) $out .= "<subject>$subject</subject>";
    $out .= "<body>$body</body>";
    if($payload) $out .= $payload;
    $out .= "</message>";
    echo date('Y-m-d H:i:s', microtime(1))." Sending message to $to\n";
    $this->send($out);
  }

}

class XMPPHP_XMLObj {
  public $name;
  public $ns;
  public $attrs = array();
  public $subs = array();
  public $data = '';
  public function __construct($name, $ns = '', $attrs = array(), $data = '') {
    $this->name = strtolower($name);
    $this->ns   = $ns;
    if(is_array($attrs) && count($attrs)) {
      foreach($attrs as $key => $value) {
        $this->attrs[strtolower($key)] = $value;
      }
    }
    $this->data = $data;
  }
  public function hasSub($name, $ns = null) {
    foreach($this->subs as $sub) {
      if(($name == "*" or $sub->name == $name) and ($ns == null or $sub->ns == $ns)) return true;
    }
    return false;
  }
  public function sub($name, $attrs = null, $ns = null) {
    foreach($this->subs as $sub) {
      if($sub->name == $name and ($ns == null or $sub->ns == $ns)) {
        return $sub;
      }
    }
  }
}

