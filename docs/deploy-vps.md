# VPS / cloud VM install

> Recommended path for self-hosting Dixlase. Examples below assume Ubuntu 22.04 LTS; adapt the package names for your distribution.

The one-liner handles the application bootstrap (download, dependencies, key generation, permissions, storage link). The webserver, PHP runtime, and database still need to be set up separately on the host.

## 1. Provision the OS prerequisites

```bash
# Add the maintained PHP repository on Ubuntu / Debian
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# PHP 8.3 + the extensions install.php requires
sudo apt install -y \
    php8.3 php8.3-{cli,fpm,common,mbstring,xml,curl,gd,bcmath,sqlite3,zip} \
    php8.3-pdo php8.3-mysql

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Nginx + MariaDB (use Postgres if you prefer)
sudo apt install -y nginx mariadb-server unzip
sudo mysql_secure_installation
```

CentOS / RHEL / Alma: use `remi-php83` plus `dnf install php php-{cli,fpm,mbstring,...}`. Arch: `pacman -S php php-fpm composer nginx mariadb`.

## 2. Create the application user and target directory

Running the installer as a dedicated unprivileged user is strongly recommended.

```bash
sudo useradd --system --create-home --shell /bin/bash dixlase
sudo mkdir -p /var/www/dixlase
sudo chown dixlase:dixlase /var/www/dixlase
```

## 3. Run the one-liner

```bash
sudo -iu dixlase bash -lc '
    cd /var/www/dixlase
    curl -sS https://install.dixlase.net | GITHUB_TOKEN=github_pat_xxx php
'
```

The token is only needed while Dixlase Core is in a private repository. Once it goes public the `GITHUB_TOKEN=` prefix can be dropped.

## 4. Create the database

```bash
sudo mysql <<'SQL'
CREATE DATABASE dixlase CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dixlase'@'localhost' IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON dixlase.* TO 'dixlase'@'localhost';
FLUSH PRIVILEGES;
SQL

# Update the .env with the credentials
sudo -iu dixlase sed -i.bak \
    -e 's/^DB_HOST=.*/DB_HOST=127.0.0.1/' \
    -e 's/^DB_DATABASE=.*/DB_DATABASE=dixlase/' \
    -e 's/^DB_USERNAME=.*/DB_USERNAME=dixlase/' \
    -e 's/^DB_PASSWORD=.*/DB_PASSWORD=change-me/' \
    /var/www/dixlase/.env

sudo -iu dixlase php /var/www/dixlase/artisan migrate --force
```

## 5. Configure Nginx + PHP-FPM

Add `/etc/nginx/sites-available/dixlase.conf`:

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
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        # Core updates can exceed nginx's default 60s; allow PHP's 300s.
        fastcgi_read_timeout 300s;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

Enable and reload:

```bash
sudo ln -s /etc/nginx/sites-available/dixlase.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## 6. Add HTTPS with Let's Encrypt

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d dixlase.example.com
# certbot will rewrite the vhost to listen on 443 and renew the cert via cron
```

## 7. First-time setup in the browser

Open `https://dixlase.example.com` — the Dixlase install wizard takes over:

1. Confirm the database connection
2. Create the initial admin account
3. Set the mail server (or leave it for later)

## Hardening checklist (post-install)

- File permissions: keep ownership at `dixlase:dixlase`, `storage/` and `bootstrap/cache/` writable, everything else read-only for the webserver user
- `sudo systemctl enable --now php8.3-fpm nginx mariadb`
- `ufw allow 'Nginx Full' && ufw enable` (or your distribution's firewall)
- Fail2ban or CrowdSec for SSH + HTTP brute-force protection
- Set up backups: `mysqldump` of the DB + `storage/app/` tree
- Add a non-root systemd unit for the Laravel queue if you use queues:
  `php artisan queue:work` as a `systemd` service running as `dixlase`
- Add a Laravel scheduler cron: `* * * * * cd /var/www/dixlase && php artisan schedule:run >> /dev/null 2>&1` (run as `dixlase`)

## Updating Dixlase later

The one-liner is for *initial* install. For updates, deal with composer + git directly:

```bash
# 1) As the app user: enter maintenance, update code, migrate.
sudo -iu dixlase bash -lc '
    cd /var/www/dixlase
    php artisan down
    GITHUB_TOKEN=github_pat_xxx composer update --no-dev --optimize-autoloader
    php artisan migrate --force
'

# 2) As root: reload PHP-FPM so opcache and the realpath cache drop the old
#    code instead of serving stale/half-swapped files after the swap.
sudo systemctl reload php8.3-fpm

# 3) As the app user: lift maintenance.
sudo -iu dixlase bash -lc 'cd /var/www/dixlase && php artisan up'
```
