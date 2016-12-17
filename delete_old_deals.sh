#!/bin/bash
# скрипт для удаления старых сделок по крону. принцип работы состоит в том, что при открытии в браузере истёкшей сделки она автоматом удаляется, ищи $timeout в коде
# конечно, лучше делать это через базу, сравнивая текущее время с changed + timeout, но мне лень
mysql -u test -p12345 test -e 'select url_seller from deals;' > /dev/shm/url_seller;
echo "total deals:";
wc -l /dev/shm/url_seller;
echo "checking urls";
for i in $(cat /dev/shm/url_seller);
do 
 echo -n ".";
 curl -s http://1.2.3.4/garant/index.php?seller=$i >/dev/null 2>/dev/null;
done
mysql -u test -p12345 test -e 'select url_seller from deals;' > /dev/shm/url_seller;
echo "remaining deals:";
wc -l /dev/shm/url_seller;

