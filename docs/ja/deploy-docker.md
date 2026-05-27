# Docker でのインストール

> 「本番形のローカルスタック」 — Nginx + PHP-FPM + MariaDB + Redis + Mailpit + Adminer を `docker compose up` 一発で。

ワンライナーインストーラ (`install.php`) は Docker セットアップ用の道具では**ありません**。Dixlase には専用の Docker インストーラリポジトリがあります:

**[Dixlase/dixlase-installer-docker](https://github.com/Dixlase/dixlase-installer-docker)**

このガイドは基本的にそちらへの案内です。なぜ別リポなのか、それぞれの適用シーンを補足します。

## なぜ 2 つのインストーラがある?

| | `dixlase-oneliner-installer` (本リポ) | `dixlase-installer-docker` |
| --- | --- | --- |
| 想定ユーザー | サーバを 1 台プロビジョニングするオペレーター | ローカル開発者 / 評価者 |
| 配布形態 | `install.dixlase.net` で配信される PHP 1 ファイル | `setup.sh` + Docker Compose を含むリポジトリ |
| 出力物 | ホスト上の Laravel アプリツリー | 稼働中のコンテナスタック |
| Web サーバ | 別途構築 | 同梱 (コンテナ Nginx) |
| データベース | 別途構築 | 同梱 (コンテナ MariaDB) |
| ベストマッチ | VPS / クラウド VM / SSH 対応レンタル | ローカル開発、デモ、評価 |

どちらも最終的に同じ Dixlase Core を動かすことになります。違いは「ランタイムをどう組み立てるか」です。

## Docker インストーラのクイックスタート

```bash
git clone https://github.com/Dixlase/dixlase-installer-docker.git ~/dixlase-docker
cd ~/dixlase-docker
./setup.sh

# 本番モード (Vite ビルド済みアセット): デフォルト
# 開発モード (Vite hot-reload):
#   ./setup.sh --dev
```

`setup.sh` が完了したら以下にアクセス:

- `http://localhost` — Dixlase (`.env` で `HTTPS=true` にして `setup.sh` 再実行で HTTPS 化)
- `http://localhost:8081` — Adminer (DB ブラウザ)
- `http://localhost:8025` — Mailpit (メールキャッチャー)

初回ブラウザアクセスで Dixlase のインストールウィザードに引き継がれます。

## ワンライナー作成済みツリーと組み合わせたい場合

`install.dixlase.net | php` で既に作成したインストールを Docker インストーラのサービス層でラップしたい、というケースは**サポート対象外の使い方**ですが (Docker インストーラは内部で core をクローンする前提) 、可能です:

1. 既存のインストール先絶対パスを控える (例: `/Volumes/Data/Works/Dixlase/Oneliner`)
2. `dixlase-installer-docker` を隣接ディレクトリにクローン
3. `docker-compose.apps.yml` の `html/` ボリュームマウントを、既存インストール先への bind マウントに置換
4. core クローンを行う `setup.sh` のステップをスキップ (それ以外は冪等)

ほとんどの状況では、ワンライナーのツリーを捨てて `./setup.sh` で作り直す方が簡単です。

## Docker インストーラを使わず自前で書きたい場合

最小限の `docker-compose.yml` を本番管理リバースプロキシ配下で運用したい、という用途では、おおまかな型はこうなります:

```yaml
services:
  app:
    image: php:8.2-fpm-alpine
    volumes:
      - ./app:/var/www/html
    depends_on: [mysql]

  web:
    image: nginx:alpine
    ports: ["8080:80"]
    volumes:
      - ./app/public:/var/www/html/public:ro
      - ./nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on: [app]

  mysql:
    image: mariadb:10.11
    environment:
      MARIADB_DATABASE: dixlase
      MARIADB_USER: dixlase
      MARIADB_PASSWORD: change-me
      MARIADB_ROOT_PASSWORD: change-me-too
    volumes:
      - mysql_data:/var/lib/mysql

volumes:
  mysql_data:
```

アプリ本体はホスト側でワンライナーを使ってブートストラップしてから、結果ツリーをマウントするのが楽です。`dixlase-installer-docker` リポの `docker-compose.*.yml` が一番近い実例リファレンスになるので、必要な設定はそちらから取り込んでください。
