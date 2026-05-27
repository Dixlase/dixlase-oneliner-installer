# VPS / クラウド VM へのインストール

> Dixlase をセルフホストするときの推奨経路。以下の例は Ubuntu 22.04 LTS を前提とします。他ディストリの場合はパッケージ名を読み替えてください。

ワンライナーはアプリケーション層のブートストラップ (ダウンロード、依存解決、key 生成、権限設定、storage link) を担当します。Web サーバ・PHP ランタイム・データベースの導入は別途必要です。

## 1. OS 前提のセットアップ

```bash
# Ubuntu / Debian: メンテされている PHP リポジトリを追加
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# PHP 8.2 と install.php が要求する拡張
sudo apt install -y \
    php8.2 php8.2-{cli,fpm,common,mbstring,xml,curl,gd,bcmath,sqlite3,zip} \
    php8.2-pdo php8.2-mysql

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Nginx + MariaDB (Postgres でも可)
sudo apt install -y nginx mariadb-server unzip
sudo mysql_secure_installation
```

CentOS / RHEL / Alma: `remi-php82` リポ + `dnf install php php-{cli,fpm,mbstring,...}`。Arch: `pacman -S php php-fpm composer nginx mariadb`。

## 2. 実行ユーザーと配置ディレクトリの作成

専用の非特権ユーザーで動かすことを強く推奨。

```bash
sudo useradd --system --create-home --shell /bin/bash dixlase
sudo mkdir -p /var/www/dixlase
sudo chown dixlase:dixlase /var/www/dixlase
```

## 3. ワンライナーを実行

```bash
sudo -iu dixlase bash -lc '
    cd /var/www/dixlase
    curl -sS https://install.dixlase.net | GITHUB_TOKEN=github_pat_xxx php
'
```

トークンは Dixlase Core が private リポジトリの間のみ必要です。public 化されたら `GITHUB_TOKEN=` 接頭辞は不要になります。

## 4. データベース作成

```bash
sudo mysql <<'SQL'
CREATE DATABASE dixlase CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dixlase'@'localhost' IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON dixlase.* TO 'dixlase'@'localhost';
FLUSH PRIVILEGES;
SQL

# .env に認証情報を反映
sudo -iu dixlase sed -i.bak \
    -e 's/^DB_HOST=.*/DB_HOST=127.0.0.1/' \
    -e 's/^DB_DATABASE=.*/DB_DATABASE=dixlase/' \
    -e 's/^DB_USERNAME=.*/DB_USERNAME=dixlase/' \
    -e 's/^DB_PASSWORD=.*/DB_PASSWORD=change-me/' \
    /var/www/dixlase/.env

sudo -iu dixlase php /var/www/dixlase/artisan migrate --force
```

## 5. Nginx + PHP-FPM 設定

`/etc/nginx/sites-available/dixlase.conf` を作成:

```nginx
server {
    listen 80;
    server_name dixlase.example.com;

    root /var/www/dixlase/public;
    index index.php;

    client_max_body_size 20M;

    add_header X-Frame-Options SAMEORIGIN always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

有効化して reload:

```bash
sudo ln -s /etc/nginx/sites-available/dixlase.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## 6. Let's Encrypt で HTTPS

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d dixlase.example.com
# certbot が vhost を 443 listen に書き換え、cron で自動更新
```

## 7. ブラウザで初回セットアップ

`https://dixlase.example.com` を開くと Dixlase のインストールウィザードが起動します:

1. データベース接続を確認
2. 管理者アカウントを作成
3. メールサーバ設定 (後回し可)

## ハードニングチェックリスト (インストール後)

- パーミッション: 所有者を `dixlase:dixlase` に、`storage/` と `bootstrap/cache/` を書込可、それ以外は Web サーバユーザから read-only
- `sudo systemctl enable --now php8.2-fpm nginx mariadb`
- `ufw allow 'Nginx Full' && ufw enable` (またはディストリ標準のファイアウォール)
- Fail2ban もしくは CrowdSec で SSH / HTTP ブルートフォース対策
- バックアップ: DB の `mysqldump` と `storage/app/` ツリー
- キューを使う場合、systemd ユニット (`dixlase` ユーザー) で `php artisan queue:work` を常駐
- Laravel スケジューラの cron: `* * * * * cd /var/www/dixlase && php artisan schedule:run >> /dev/null 2>&1` を `dixlase` ユーザーで

## アップデート運用

ワンライナーは**初回インストール用**です。アップデートは Composer / Git を直接使ってください:

```bash
sudo -iu dixlase bash -lc '
    cd /var/www/dixlase
    php artisan down
    GITHUB_TOKEN=github_pat_xxx composer update --no-dev --optimize-autoloader
    php artisan migrate --force
    php artisan up
'
```
