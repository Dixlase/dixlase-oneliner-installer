# ローカル開発環境へのインストール

> 「とりあえず手元でインストールウィザードをクリックして確認したい」を、macOS / Windows / Linux いずれでも 1 分以内で実現するガイドです。

## 最短ルート: PHP ビルトインサーバ + SQLite (30 秒)

`curl ... | php` の完了直後、インストール先には Laravel アプリ一式が揃っています。ブラウザで動かす最短は、PHP の開発サーバを SQLite に向けるだけ (追加サービスのインストールは不要)。

```bash
cd /path/to/dixlase-install   # インストール先ディレクトリ

# 新規 .env を SQLite に切替 (composer の post-create-project が
# database/database.sqlite を既に touch しています)
sed -i.bak \
    -e 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' \
    -e 's/^DB_HOST=.*/# DB_HOST=mysql/' \
    -e 's|^DB_DATABASE=.*|DB_DATABASE=database/database.sqlite|' \
    .env && rm -f .env.bak

php artisan migrate --graceful
cd public && PHP_CLI_SERVER_WORKERS=4 php -d variables_order=EGPCS -d max_execution_time=300 -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
# → http://127.0.0.1:8000
```

Windows で `sed` が無い場合は `.env` をエディタで開き、上記 3 行を手動編集してください。

ビルトインサーバは開発用途専用です。インストールウィザードのクリック確認には十分ですが、本番トラフィックを捌くものではありません。`PHP_CLI_SERVER_WORKERS=4` を付けると 4 件のリクエストを同時に処理します。既定の 1 ワーカーでは、遅いリクエストが 1 件あるだけで(管理画面のプラグイン一覧はサムネイルをサーバ側で GitHub から 1 枚ずつ取得します)他の画面がすべて止まります。Windows ではこの変数が使えないので、先頭の `PHP_CLI_SERVER_WORKERS=4` を外して実行してください。

**組み込みサーバは `artisan serve` ではなく直接起動してください。** 上のコマンドは `php artisan serve` と同じルータスクリプトで PHP の組み込みサーバを起動しますが、インストールウィザードを壊す 2 つの挙動がありません。ウィザードは進行中に `.env` を書き換えます(最終ステップは `migrate` の前後で 3 回)。`artisan serve` の既定のファイル監視は `.env` が変わるたびにサーバを再起動して処理中のリクエストを殺すため、最終ステップが `migrate` の途中で落ち、DB が半分だけできた状態になります。`--no-reload` を付けると、今度は起動時の `.env` を本物の環境変数として注入し、それが後からの `.env` の書き換えにすべて勝つため、最終ステップが起動前のデータベース設定のまま動き、完了後も `INSTALLED=false` のままになります。`-d variables_order=EGPCS` は実際の環境変数(`PATH` を含む)を `$_ENV` に入れるためのもので、これが無いと Laravel が `.env` を読み込んだ後にコアの `composer dump-autoload` が `php` を見つけられません。`-d max_execution_time=300` はよくある 30 秒制限を引き上げます。ルータは現在のディレクトリを Web ルートとして扱うので `public/` から実行してください。ウィザード完了後の再起動は不要です。

**コアの更新・ロールバックの後は、サーバを再起動してください。** コアを更新・ロールバックすると(管理画面、または `php artisan dls:core:update` / `dls:core:rollback`)、`public/` が作り直されます。動いているサーバは削除された古いディレクトリを作業ディレクトリとして持ち続けるため、すべてのリクエストが `Failed opening required '/index.php'` で失敗します。Ctrl+C で止めて同じコマンドで起動し直してください。更新自体はその時点で完了しています。

## ネイティブ Mac / Windows スタック (Laravel 向け)

日々の開発では PHP-FPM + 本物の Web サーバ + DB が欲しくなります。下記ツールは PHP バージョン切替、SSL 対応 `*.test` ホスト、MySQL/Postgres 内蔵など、Laravel 用に最適化されています。

### Laravel Herd — macOS / Windows

Laravel 開発者の事実上の標準。<https://herd.laravel.com/>

```bash
# 1. Herd を公式サイトから GUI インストール
# 2. インストール先を Herd に公開
herd park ~/Sites              # 任意の親ディレクトリ
ln -s /path/to/dixlase-install ~/Sites/dixlase

# 3. ブラウザで開く
open http://dixlase.test
```

GUI から PHP バージョンを 8.3+ に切替、必要なら `*.test` の HTTPS を有効化できます。

### Laravel Valet — macOS のみ

Herd より軽量、CLI 駆動。

```bash
composer global require laravel/valet
valet install
cd /path/to/dixlase-install
valet link dixlase
# → http://dixlase.test
```

### ddev — Docker ラッパ、クロスプラットフォーム

Mac / Windows / Linux で「本番に近いサービス構成 (MySQL / Redis)」を Docker Compose を書かずに手に入れたい場合の最有力。

```bash
cd /path/to/dixlase-install
ddev config --project-type=laravel --docroot=public --create-docroot
ddev start
ddev launch
# → https://dixlase-install.ddev.site
```

### Laragon — Windows のみ

Apache/Nginx + MySQL + PHP のバンドル、auto-vhost 付き。`C:\laragon\www\dixlase` 配下にインストール先を置くと `http://dixlase.test` でアクセスできます。

### MAMP / XAMPP / WAMP

古典的だが現役。GUI でドキュメントルートを `/path/to/dixlase-install/public` に設定 → MySQL DB 作成 → `http://localhost:<port>/` にアクセス。`.env` を合わせてください。

## データベースを選ぶ

Dixlase の評価だけなら **SQLite** で十分、セットアップ不要です。それ以外は **MySQL 8 / MariaDB 10.6+** が本番ターゲットなので推奨。

| エンジン | 用途 |
| --- | --- |
| SQLite | 動作確認、ウィザードを開くだけ |
| MySQL / MariaDB | 本格的なローカル開発、本番と同等 |
| Postgres | Laravel 自体は対応するが、Dixlase は MySQL が主検証対象。強い理由が無ければ非推奨 |

MySQL / MariaDB の場合、`.env` を以下に変更:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dixlase
DB_USERNAME=dixlase
DB_PASSWORD=secret
```

DB と権限を作成してから `php artisan migrate`。

## 開発中のメール

Dixlase は管理者招待などでメール送信を行うことになります。ローカルではキャッチャーを使うのが便利:

- **Mailpit** (推奨) — `brew install mailpit` / Windows バイナリ。`.env` に `MAIL_MAILER=smtp` `MAIL_HOST=127.0.0.1` `MAIL_PORT=1025`。Web UI は <http://localhost:8025>。
- **Mailhog** — Mailpit と同コンセプト、世代は古め。
- **log ドライバ** — `MAIL_MAILER=log` で `storage/logs/laravel.log` に出力 (UI 無し、依存ゼロ)。

## 目的別の推奨

| 目的 | 推奨 |
| --- | --- |
| 初めてウィザードを試す | PHP ビルトイン + SQLite |
| 日常の Mac/Win Laravel 開発 | Laravel Herd |
| 本番に近いローカルスタック | [Docker インストーラー](./deploy-docker.md) または ddev |
| CI でバージョン固定の再現性 | ddev または Docker インストーラー |
