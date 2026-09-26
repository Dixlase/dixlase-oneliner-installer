# VPS / クラウド VM へのインストール

> Dixlase をセルフホストするときの推奨経路。以下の例は Ubuntu 22.04 LTS を前提とします。他ディストリの場合はパッケージ名を読み替えてください。

ワンライナーはアプリケーション層のブートストラップ (ダウンロード、依存解決、key 生成、権限設定、storage link) を担当します。Web サーバ・PHP ランタイム・データベースの導入は別途必要です。

## 1. OS 前提のセットアップ

```bash
# Ubuntu / Debian: メンテされている PHP リポジトリを追加
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# PHP 8.3 と install.php が要求する拡張
sudo apt install -y \
    php8.3 php8.3-{cli,fpm,common,mbstring,xml,curl,gd,bcmath,sqlite3,zip} \
    php8.3-pdo php8.3-mysql

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Nginx + MariaDB (Postgres でも可)
sudo apt install -y nginx mariadb-server unzip
sudo mysql_secure_installation
```

CentOS / RHEL / Alma: `remi-php83` リポ + `dnf install php php-{cli,fpm,mbstring,...}`。Arch: `pacman -S php php-fpm composer nginx mariadb`。

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
    curl -sS https://install.dixlase.net | GITHUB_TOKEN=github_pat_xxx php -- --dir=/var/www/dixlase
'
```

トークンは Dixlase Core が private リポジトリの間のみ必要です。public 化されたら `GITHUB_TOKEN=` 接頭辞は不要になります。

`--dir` を付けないと、カレントディレクトリに `dixlase/` サブディレクトリを作ってその中に入れるため、パスを明示してください。ワンライナーは初回インストール専用です。既存のサイトに対して再実行すると、何も変更せずに停止します ([アップデート運用](#アップデート運用) を参照)。

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

`/etc/nginx/sites-available/dixlase.conf` を作成します。手順 7 のインストールウィザードが終わるまでは、最初にサイトを開いた人が管理者アカウントを作れてしまうため、当面は自分の IP アドレスだけを通します (`203.0.113.10` を置き換えてください。自分の端末で `curl -s https://ifconfig.me` を実行すると確認できます):

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
        # インストールウィザードが終わったら削除 (手順 7)
        allow 203.0.113.10;
        deny all;

        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        # インストールウィザードが終わったら削除 (手順 7)
        allow 203.0.113.10;
        deny all;

        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        # コア更新は nginx 既定の 60s を超え得るため PHP の 300s まで許可
        fastcgi_read_timeout 300s;
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

ウィザードが終わったら、両方の location から `allow` / `deny` の行を削除して nginx を reload します:

```bash
sudo nano /etc/nginx/sites-available/dixlase.conf   # allow/deny の行を削除
sudo nginx -t && sudo systemctl reload nginx
```

ブラウザの代わりに、手順 5 の前にシェルから `php artisan dls:install` でセットアップを済ませることもできます (`php artisan dls:install --help` を参照)。その場合、vhost に IP 制限は要りません。

## ハードニングチェックリスト (インストール後)

- パーミッション: 所有者を `dixlase:dixlase` に、`storage/` と `bootstrap/cache/` を書込可、それ以外は Web サーバユーザから read-only
- `sudo systemctl enable --now php8.3-fpm nginx mariadb`
- `ufw allow 'Nginx Full' && ufw enable` (またはディストリ標準のファイアウォール)
- Fail2ban もしくは CrowdSec で SSH / HTTP ブルートフォース対策
- バックアップ: DB の `mysqldump` と `storage/app/` ツリー
- キューを使う場合、systemd ユニット (`dixlase` ユーザー) で `php artisan queue:work` を常駐
- Laravel スケジューラの cron: `* * * * * cd /var/www/dixlase && php artisan schedule:run >> /dev/null 2>&1` を `dixlase` ユーザーで

## アップデート運用

ワンライナーは**初回インストール用**で、既存のサイトに対しては停止します。コアの更新は (アプリユーザーで) `php artisan dls:core:update` を使うか、Composer / Git を直接使ってください:

```bash
# 1) アプリユーザーで: メンテナンス開始 → コード更新 → マイグレーション
sudo -iu dixlase bash -lc '
    cd /var/www/dixlase
    php artisan down
    GITHUB_TOKEN=github_pat_xxx composer update --no-dev --optimize-autoloader
    php artisan migrate --force
'

# 2) root で: PHP-FPM を reload し、opcache と realpath キャッシュに古いコードを
#    破棄させる (swap 後に古い/半置換のファイルを配信しないため)
sudo systemctl reload php8.3-fpm

# 3) アプリユーザーで: メンテナンス解除
sudo -iu dixlase bash -lc 'cd /var/www/dixlase && php artisan up'
```
