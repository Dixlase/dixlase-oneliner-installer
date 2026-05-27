# 共用 / レンタルホスティングへのインストール

> ワンライナーが使えるかどうかは、契約プランが何を許すかで完全に決まります。本ガイドでは現実的な 2 パターンに分けて解説します。

## 事前チェックリスト

着手前にコントロールパネルで以下を確認してください:

- [ ] **SSH** が使える (ワンライナーはシェルコマンドなので、シェルが無いと動きません)
- [ ] **PHP 8.2 以降**がアカウントで選択できる
- [ ] **PHP 拡張**: `openssl pdo mbstring tokenizer xml ctype json bcmath curl fileinfo gd` がすべて有効
- [ ] **Composer** が PATH 上にある (上位プランは大抵同梱。SSH で `composer --version` で確認)
- [ ] **MySQL / MariaDB** データベースをパネルから作成できる
- [ ] **外向き HTTPS** が `github.com` / `api.github.com` / `packagist.org` / `install.dixlase.net` に対してブロックされていない
- [ ] **ドキュメントルート**をサブディレクトリに向けられる (プロジェクトルートではなく `public/` を晒す必要がある)

全部が "はい" のプラン → [パターン A](#パターン-a--ssh--composer--php-82-のプラン)。SSH が無い場合 → [パターン B](#パターン-b--sftp-のみのプラン)。

## パターン A — SSH + Composer + PHP 8.2+ のプラン

該当しやすい例: さくらのレンタルサーバ (スタンダード以上)、エックスサーバー (Business 含む)、mixhost、ConoHa WING、KAGOYA。最新のプラン仕様は契約前に必ず再確認してください。

### 1. SSH 接続してワンライナーを実行

```bash
ssh user@your-rental.example.jp
cd ~/www                              # パネル管理の Web ルート (ホストによる)
mkdir dixlase && cd dixlase
curl -sS https://install.dixlase.net | GITHUB_TOKEN=github_pat_xxx php
```

デフォルト PATH の `php` が古い場合 (パネルでは PHP 8.2+ を選んでいても SSH の既定が古いケースは多い)、ホストが用意している `/usr/local/php/8.2/bin/php` 等のパスを明示してください:

```bash
curl -sS https://install.dixlase.net | GITHUB_TOKEN=github_pat_xxx /usr/local/php/8.2/bin/php
```

### 2. ドキュメントルートを `dixlase/public` に向ける

インストーラの仕事ではなく**コントロールパネルでの設定**です。多くのパネルでサブドメインごとに「公開フォルダ」「Web ルート」欄があります。

ドキュメントルートを変更できないホストでは、パネル管理の Web ルートに以下 1 ファイルだけ `.htaccess` を置き、すべてのリクエストを `public/` 配下にリライト:

```apache
DirectoryIndex disabled
RewriteEngine On
RewriteRule ^$ public/ [L]
RewriteRule ^((?!public/).*)$ public/$1 [L,NC]
```

### 3. パネルから DB を作成して `.env` 編集

レンタルパネルは DB ホスト名・ユーザー名・DB 名を自動生成してくれることがほとんど。それを `~/www/dixlase/.env` の `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` に反映。

### 4. マイグレーション実行

```bash
cd ~/www/dixlase
php artisan migrate --force
```

### 5. URL を開けばインストールウィザードが続きを案内

## パターン B — SFTP のみのプラン (SSH 無し)

廉価な共用プラン (ロリポップ「ライト」、初級向け各種) ではシェルから `php` を実行できません。ワンライナーを直接は使えないので、**手元でインストール → SFTP でアップロード**が現実解です。

### 1. ローカルでまずインストール完走

[deploy-local-dev.md](./deploy-local-dev.md) に従って `/path/to/dixlase-install/` を作ります。

### 2. SFTP / rsync でアップロード

ローカル固有のものを除外して全部送ります:

```bash
rsync -avz \
    --exclude='.git' --exclude='node_modules' \
    --exclude='.env' --exclude='database/database.sqlite' \
    /path/to/dixlase-install/  user@host:/path/to/public_html/
```

### 3. ホスト側の `.env` をセットアップ

SFTP やパネルのファイルマネージャから `.env.example` を `.env` にコピーし、ホストの DB 認証情報を埋めます。

アプリキーは手元で生成して結果を貼り付ける形 (ホストにシェルは不要):

```bash
# 手元の使い捨てクローンで実行
php artisan key:generate --show
# 出力された base64:... 値を、アップロードした .env の APP_KEY=... に貼り付け
```

### 4. マイグレーションを実行

パネルに「スケジュールタスク」「cron」「PHP CLI 実行」機能があれば以下を 1 回実行:

```
php /path/to/public_html/artisan migrate --force
```

無い場合は `public/setup.php` のような一度きりのブートストラップを置き、ブラウザから叩く → 終わったら削除、という手順になります。URL にワンショットトークンを付けるなど第三者実行への対策を忘れずに。

### 5. パネルのドキュメントルートをアップロード先の `public/` に向ける

## レンタルホスティングでよくある落とし穴

| 症状 | 想定原因 |
| --- | --- |
| `composer create-project failed` がメッセージ無しに出る | ホストのアウトバウンドファイアウォールが `api.github.com` / `packagist.org` を遮断。サポートに許可申請、または SFTP 方式に切替 |
| `Class App\... does not comply with PSR-4` 警告が composer install 中に出る | dixlase-core 側の既存の問題。ホスト要因ではなく、現状は無視可 |
| `php_network_getaddresses: getaddrinfo for mysql failed` | `.env` がまだ `DB_HOST=mysql` (Docker 既定) のまま。パネル表示のホスト名に書き換え |
| 数 MB を超えるファイルのアップロードが失敗 | `upload_max_filesize` / `post_max_size` を引き上げ (パネル設定または `php.ini` で上書き) |
| すべてのページが 500 | `storage/logs/laravel.log` を確認。ほぼ常に PHP 拡張不足か `storage/` の書込権限不足 |

## マネージド PaaS (Heroku / Render / Railway / Fly.io / Cloud Run)

マネージド PaaS は永続シェルを公開せず、ビルドは git push もしくはコンテナイメージから行われます。ワンライナーは適切なツールではありません。各プラットフォームのネイティブデプロイ経路を使ってください:

1. `Dixlase/dixlase-core` を自分の GitHub アカウントに fork または clone (private で OK)
2. プラットフォーム指定のデプロイ記述子 (`Procfile`、`render.yaml`、`fly.toml`、`Dockerfile` 等) を追加
3. `COMPOSER_AUTH` / `GITHUB_TOKEN` をビルド時のシークレットとして設定し、テーマ依存が解決できるようにする
4. push → プラットフォーム側で `composer install` が走る

雛形には [DixlaseInstallerDocker](https://github.com/Dixlase/dixlase-installer-docker) の Dockerfile が出発点として使えます。
