#!/bin/bash -eux

# 各種設定ファイルのコピー
sudo cp -f etc/mysql/mysql.conf.d/mysqld.cnf /etc/mysql/mysql.conf.d/mysqld.cnf
sudo cp -f etc/nginx/nginx.conf /etc/nginx/nginx.conf
sudo cp -f etc/nginx/sites-available/isuconquest-php.conf /etc/nginx/sites-available/isuconquest-php.conf
sudo cp -rf etc/php/etc/. /home/isucon/local/php/etc/.
sudo nginx -t

# アプリケーションのビルド
cp -rf ../app/webapp/. /home/isucon/webapp/.
cd /home/isucon/webapp/php
php composer.phar install

# ミドルウェア・Appの再起動
# sudo systemctl restart mysql
# sudo systemctl reload nginx
# sudo systemctl restart isuconquest.php

# slow query logの有効化
#QUERY="
#set global slow_query_log_file = '/var/log/mysql/mysql-slow.log';
#set global long_query_time = 0.01;
#set global slow_query_log = ON;
#"
#echo $QUERY | sudo mysql -uroot

# log permission
sudo chmod -R 777 /var/log/nginx
sudo chmod -R 777 /var/log/mysql
