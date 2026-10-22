# Dixlase ワンライナーインストーラー ドキュメント

`curl -sS https://install.dixlase.net | php` は、シェル・PHP 8.3+・外向き HTTPS が揃っていればどの環境でも動きます。本セクションでは代表的な配置シナリオごとに手順を整理します。

英語版は [../index.md](../index.md) を参照。

## シナリオから選んでください

| シナリオ | ガイド | 用途 |
| --- | --- | --- |
| ローカル開発 (Mac / Windows / Linux) | [deploy-local-dev.md](./deploy-local-dev.md) | 初めての評価、コントリビューター環境、「とりあえずインストールウィザードを開きたい」 |
| ローカル Docker | [deploy-docker.md](./deploy-docker.md) | 本番に近い構成 (Nginx + PHP-FPM + MariaDB + Redis + Mailpit) でローカル検証 |
| VPS / クラウド VM | [deploy-vps.md](./deploy-vps.md) | Dixlase をセルフホストする推奨経路 |
| 共用 / レンタルホスティング | [deploy-shared-hosting.md](./deploy-shared-hosting.md) | SSH + PHP 8.3+ のプラン (および SFTP のみのプランでの回避策) |
| トラブルシュート | [troubleshooting.md](./troubleshooting.md) | よくある失敗と原因・修正手順 |

## 前提条件のまとめ

インストーラーは常に以下を必要とします:

- **PHP 8.3 以降** と標準拡張 (`openssl`、`pdo`、`mbstring`、`tokenizer`、`xml`、`ctype`、`json`、`bcmath`、`curl`、`fileinfo`、`gd`)
- **Composer** (推奨。ZIP に `vendor/` が無いときと `--method=composer` の経路で使用)
- **Node.js 24** (または `^20.19 || >=22.12` の範囲) — フロントエンドアセットのビルドが必要な場合のみ。`--no-build` でビルド自体を省略可能
- **外向き HTTPS** が `github.com` / `api.github.com` / `packagist.org` / `install.dixlase.net` に対して通ること
- `curl ... | php` を実行できる**シェル** (したがって SFTP のみのホストでは直接使えません。回避策は [deploy-shared-hosting.md](./deploy-shared-hosting.md) を参照)

GitHub トークンは不要です — Dixlase のリポジトリはすべて public です。トークンは GitHub API のレート制限 (匿名は IP ごとに 1 時間 60 回) を上げるだけで、役に立つ場面は [README.ja.md](../../README.ja.md#オプション-github-トークン)、配布経路の詳細は [動作の仕組み](../../README.ja.md#動作の仕組み) を参照してください。

## PHP を最新に保つ

Dixlase を更新しても PHP は更新されません。PHP は OS、バージョン管理ツール、ホストの管理画面から来るので、PHP のセキュリティリリースがサイトに届くのは PHP 自体を更新したときだけです。インストーラは、動いている PHP がその系列の最新のセキュリティ修正を欠いているとき警告を出します。

| 配置 | PHP の更新方法 |
| --- | --- |
| VPS / クラウド VM | PHP のパッケージを上げ(例 `sudo apt update && sudo apt upgrade 'php8.3*'`)、`sudo systemctl reload php8.3-fpm` で PHP-FPM に新しいバイナリを使わせる |
| ローカル(PHP の組み込みサーバー) | パッケージマネージャやバージョン管理ツール(Homebrew、Herd など)で PHP を上げ、サーバーを止めて(Ctrl+C)起動し直す |
| 共用 / レンタルサーバー | 管理画面で PHP 8.3 以降の最新のパッチリリースを選ぶ。またはセキュリティリリースをいつ当てるかホストに問い合わせる |
| Docker インストーラ | `./update.sh --rebuild` で PHP のイメージを作り直す — [Docker インストーラ](https://github.com/Dixlase/dixlase-docker-installer)の README を参照 |

結果は `php -v`(CLI)と、PHP-FPM については `PHP_VERSION` を表示するページで確かめます。PHP が複数入っていると、両者が食い違うことがあります。
