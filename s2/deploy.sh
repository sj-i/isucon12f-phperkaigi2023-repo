#!/bin/bash -eux

# 各種設定ファイルのコピー
sudo cp -f env.sh /home/isucon/env
#sudo cp -f etc/mysql/mariadb.conf.d/50-server.cnf /etc/mysql/mariadb.conf.d/50-server.cnf
#sudo cp -f etc/nginx/nginx.conf /etc/nginx/nginx.conf
#sudo cp -f etc/nginx/sites-available/isucondition.conf /etc/nginx/sites-available/isucondition.conf
#sudo nginx -t

# アプリケーションのビルド
#cd /home/isucon/webapp/php
#php composer.phar install

# ミドルウェア・Appの再起動
sudo systemctl restart nginx --now
sudo systemctl restart mysql
sudo systemctl restart isuconquest.php --now
