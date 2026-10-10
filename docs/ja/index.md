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
