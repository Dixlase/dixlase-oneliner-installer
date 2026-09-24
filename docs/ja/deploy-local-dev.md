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
php artisan serve
# → http://127.0.0.1:8000
```

Windows で `sed` が無い場合は `.env` をエディタで開き、上記 3 行を手動編集してください。

ビルトインサーバはシングルスレッドで開発用途専用です。インストールウィザードのクリック確認には十分ですが、本番トラフィックを捌くものではありません。

**`artisan serve` とウィザードについて 2 点。** 既定のファイル監視はそのままにして、`--no-reload` は**付けないでください**。ウィザードは進行中に `.env` を書き換えますが、組み込みサーバは起動時に読んだ値をプロセスが生きている間ずっと保持するため (Laravel の環境変数リポジトリはイミュータブル)、監視による再起動が入って初めて新しいデータベース設定が見えます。`--no-reload` を付けると、最終ステップが起動前のデータベース設定のまま実行され、接続エラーでインストールが失敗します。再起動直後に開けないページはリロードすれば表示され、最終ステップが落ちた場合はもう一度実行すれば大丈夫です (マイグレーション前に DB をリセットします)。そしてウィザード完了後は、**自分でサーバを再起動してください**。管理ルートの prefix と有効テーマの view 名前空間は起動時に DB から読まれるため、インストール前に起動したサーバはインストール前の状態を配信し続けます (フロントはエラー、管理画面のパスは 404)。

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
