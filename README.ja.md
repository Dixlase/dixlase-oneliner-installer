For English, see [README.md](./README.md).

# Dixlase ワンライナーインストーラー

[![CI](https://github.com/Dixlase/dixlase-oneliner-installer/actions/workflows/ci.yml/badge.svg)](https://github.com/Dixlase/dixlase-oneliner-installer/actions/workflows/ci.yml)

[Dixlase](https://github.com/Dixlase/dixlase-core) を 1 行のシェルコマンドでインストールする、単一ファイルの PHP スクリプトです。

## クイックスタート

```bash
curl -sS https://install.dixlase.net | php
```

配布経路を自動選択し、`<cwd>/dixlase` に展開してセットアップまで完了します。`php install.php` で直接実行した場合は、インストール先と続行確認を尋ねる対話モードになります。

**データベース:** お試しや小規模なサイトなら、インストールウィザードの Database で **SQLite** を選んでください。DB サーバは不要です (PHP の `pdo_sqlite` 拡張だけ必要)。本番運用では MySQL / MariaDB を使ってください ([docs/ja/deploy-vps.md](docs/ja/deploy-vps.md) を参照)。

## 動作要件

- **PHP 8.3 以上** と標準拡張 (`openssl`、`pdo`、`mbstring`、`tokenizer`、`xml`、`ctype`、`json`、`bcmath`、`curl`、`fileinfo`、`gd`)
- **Composer** (推奨)
- **Node.js 24** (または Vite 8 が対応する `^20.19 || >=22.12` の範囲) — フロントエンドアセットのビルドが必要な場合のみ。リリース ZIP はビルド済みアセットを同梱
- **ネットワーク接続** (GitHub Releases / Packagist)

不足はインストーラーが事前チェックして対処手順を表示します。

## 動作の仕組み

配布経路は 2 つあり、自動で選択されます:

1. **`composer create-project`** (優先) — `PATH` に Composer がある場合に使用、Packagist 経由で依存解決。
2. **GitHub Releases ZIP** (フォールバック) — `dixlase-v<version>.zip` をダウンロード → SHA-256 検証 → 展開 → Composer があれば `composer install`。

どちらの経路でも `.env` 生成、`APP_KEY` 生成 (空の場合のみ)、`storage/` 等の権限設定、`storage:link` の共通後処理が走ります。DB・管理者・メールの設定は初回ブラウザアクセス時のウィザードで案内されます。

## オプション

```
オプション:
  --dir=PATH          インストール先ディレクトリ (デフォルト: <cwd>/dixlase)。
                      --dir=. でサブディレクトリを作らずカレント直下に展開
  --version=X.X.X     特定のバージョンを指定 (デフォルト: 最新)
  --method=MODE       配布方式: auto, composer, zip (デフォルト: auto)
  --no-composer       zip フォールバックで "composer install" をスキップ
  --no-build          "npm ci && npm run build" をスキップ
  --non-interactive   STDIN がターミナルでもプロンプトを無効化
  -y, --yes           全プロンプトを自動承認
  --force-reinstall   既存サイトの上に再インストール (APP_KEY は保持)
  -h, --help          ヘルプを表示
```

インストーラは新規インストール専用です。インストール先に既存のサイト (`.env`・`artisan`・`bootstrap/app.php`・`vendor/` のいずれか) があると、何も変更せずに停止します。既存サイトの更新は `php artisan dls:core:update` で行ってください。`--force-reinstall` を付けるとファイルを上書きしますが、既存の `.env` と `APP_KEY` は保持するため、暗号化されたデータは読めるままです。

例:

```bash
# 任意の場所にインストール
curl -sS https://install.dixlase.net | php -- --dir=/var/www/dixlase

# バージョン固定
curl -sS https://install.dixlase.net | php -- --version=1.0.0

# CI 用 (非対話 + 自動承認)
php install.php --non-interactive --yes --dir=/srv/dixlase
```

## インストーラの版

インストーラには独自の版があり、バナーの下(`Installer v0.1.1`)と [CHANGELOG.ja.md](./CHANGELOG.ja.md) に載っています。Dixlase コアの版とは別で、上の `--version` が選ぶのは、インストールする**コア**の版です。

`install.dixlase.net` は最新のインストーラのリリースを配っています。決まった版のインストーラを使う場合は、タグから取得してください:

```bash
curl -sS https://raw.githubusercontent.com/Dixlase/dixlase-oneliner-installer/v0.1.1/install.php | php
```

## ローカライゼーション

`install.php` のコメントと UI メッセージは英語が既定。日本語への切り替え / 復元は付属スクリプトで:

```bash
./convert-comments.sh ja                # 全ファイル: 英 → 日
./convert-comments.sh ja --reverse      # 全ファイル: 日 → 英 (復元)
```

辞書は `lang/<locale>/<source-path>.tsv` (タブ区切りの `<英語>\t<ロケール>` ペア)。詳細は [CLAUDE.md](./CLAUDE.md) を参照。

## ドキュメント

シナリオ別のデプロイガイドは [`docs/ja/`](./docs/ja/) 配下:

- [ローカル開発環境](./docs/ja/deploy-local-dev.md) — PHP ビルトイン / Laravel Herd / ddev 等
- [Docker](./docs/ja/deploy-docker.md)
- [VPS / クラウド VM](./docs/ja/deploy-vps.md) — Ubuntu 例
- [共用 / レンタルホスティング](./docs/ja/deploy-shared-hosting.md) — SFTP・PaaS 含む
- [トラブルシュート](./docs/ja/troubleshooting.md)

英語版は [`docs/`](./docs/)。

## リポジトリ構成

```
.
├── install.php             # 単一ファイルの PHP インストーラー
├── convert-comments.sh     # コメント / メッセージのロケール切替
├── lang/{en,ja}/           # 翻訳辞書 (TSV)
├── docs/, docs/ja/         # デプロイガイド (英 / 日)
├── tests/                  # bats テスト一式
├── .github/workflows/      # GitHub Actions CI
├── CLAUDE.md, CLAUDE.ja.md # コーディングルール
└── LICENSE                 # MIT
```

## テスト

[bats](https://github.com/bats-core/bats-core) スイートが、ヘルプ、引数バリデーション、モック GitHub サーバを使った ZIP 経路 E2E、`convert-comments.sh` の往復、バナーの桁数チェックを網羅します。

```bash
brew install bats-core              # macOS
sudo apt-get install -y bats        # Debian / Ubuntu

bats tests/
```

CI は PHP 8.3 / 8.4 / 8.5 のマトリクスで同じスイートを実行 ([.github/workflows/ci.yml](./.github/workflows/ci.yml))。

### URL の上書き

`install.php` は 2 つのオプション環境変数を読み、テスト / 内部ミラー / エアギャップ環境で配信元を切り替えられます:

| 環境変数 | デフォルト | 用途 |
| --- | --- | --- |
| `DIXLASE_API_LATEST_URL` | `https://api.github.com/repos/Dixlase/dixlase-core/releases/latest` | `{"tag_name": "vX.Y.Z"}` を返すエンドポイント |
| `DIXLASE_RELEASE_URL_BASE` | `https://github.com/Dixlase/dixlase-core/releases/download` | `/v<ver>/dixlase-v<ver>.zip` 等を取得するベース URL |

## ライセンス

本インストーラー (`install.php` と関連スクリプト / 辞書) は **MIT ライセンス**で公開しています。詳細は [LICENSE](./LICENSE) を参照。コントリビューションに CLA への同意は不要です。Dixlase 本体は AGPL v3 ([Dixlase Core](https://github.com/Dixlase/dixlase-core))。
