# Dixlase ワンライナーインストーラー

[![CI](https://github.com/Dixlase/dixlase-oneliner-installer/actions/workflows/ci.yml/badge.svg)](https://github.com/Dixlase/dixlase-oneliner-installer/actions/workflows/ci.yml)

[Dixlase](https://github.com/Dixlase/dixlase-core) を 1 行のシェルコマンドでサーバーにインストールするための、単一ファイルの PHP スクリプトです。

For English, see [README.md](./README.md).

## クイックスタート

```bash
curl -sS https://install.dixlase.com | php
```

これだけで、環境に応じて最適な配布経路が自動選択され、Dixlase のセットアップまで完了します。`php install.php` でローカル実行した場合は、インストール先ディレクトリを尋ねた上で続行確認を行う対話モードになります。

## 動作要件

- **PHP 8.2 以上** と標準拡張 (`openssl`、`pdo`、`mbstring`、`tokenizer`、`xml`、`ctype`、`json`、`bcmath`、`curl`、`fileinfo`、`gd`)
- **Composer** (推奨 — 主配布経路で使用)
- **ネットワーク接続** (GitHub: ZIP フォールバック用 / Packagist: `composer create-project` 用)

上記はすべてインストーラーが事前チェックします。不足がある場合は具体的な対処手順を表示します。

## 動作の仕組み

インストーラーは以下 2 つの配布経路から自動で選択します:

1. **`composer create-project`** (優先) — `PATH` に Composer が存在するときに使用。`composer create-project dixlase/dixlase-core <dir>` を実行し、依存解決は Packagist に任せます。
2. **GitHub Releases ZIP** (フォールバック) — `github.com/Dixlase/dixlase-core/releases` から `dixlase-v<version>.zip` をダウンロードし、SHA-256 チェックサム検証 → 展開 → Composer がある場合は `composer install` を実行します。

どちらの経路でも、最後に共通の後処理 (`.env.example` → `.env` のコピー、`APP_KEY` 生成、`storage/` と `bootstrap/cache/` の権限設定、`storage:link` 作成) が実行されます。データベース・管理者アカウント・メール設定は、初回のブラウザアクセス時に Dixlase のインストールウィザードが案内します。

## オプション

```
使い方:
  curl -sS https://install.dixlase.com | php
  curl -sS https://install.dixlase.com | php -- [options]
  php install.php [options]

オプション:
  --dir=PATH          インストール先ディレクトリ (デフォルト: カレントディレクトリ)
  --version=X.X.X     特定のバージョンをインストール (デフォルト: 最新)
  --method=MODE       配布方式: auto, composer, または zip (デフォルト: auto)
  --no-composer       zip フォールバックパスで "composer install" をスキップ
  --non-interactive   STDIN がターミナルでもプロンプトを無効化
  -y, --yes           全プロンプトを自動承認
  -h, --help          このヘルプメッセージを表示
```

例:

```bash
# 最新版を /var/www/dixlase にインストール
curl -sS https://install.dixlase.com | php -- --dir=/var/www/dixlase

# 特定のバージョンを固定
curl -sS https://install.dixlase.com | php -- --version=1.0.0

# Composer があっても ZIP フォールバックを強制
curl -sS https://install.dixlase.com | php -- --method=zip

# ローカル対話実行
php install.php

# ローカル非対話実行 (CI 用)
php install.php --non-interactive --yes --dir=/srv/dixlase
```

## ローカライゼーション

`install.php` のコメントと UI メッセージはデフォルトで英語です。日本語に切り替える (または戻す) には付属の変換スクリプトを使います:

```bash
./convert-comments.sh ja                # 全ファイル: 英語 → 日本語
./convert-comments.sh ja install.php    # 単一ファイル: 英語 → 日本語
./convert-comments.sh ja --reverse      # 全ファイル: 日本語 → 英語 (復元)
```

翻訳辞書は `lang/<locale>/<source-path>.tsv` に配置されます (タブ区切りの `<英語テキスト>\t<ロケール側テキスト>` ペア)。新しいロケールを追加したり既存のものを拡張する場合は、既存ファイルと同じ場所に新しい TSV を置いてください。フォーマットの詳細は [CLAUDE.md](./CLAUDE.md) を参照してください。

## リポジトリ構成

```
.
├── install.php             # 単一ファイルの PHP インストーラー (install.dixlase.com から配信)
├── convert-comments.sh     # スクリプトのコメント / メッセージをロケール間で切り替える
├── lang/{en,ja}/           # 翻訳辞書 (TSV)
├── tests/                  # bats による結合テスト一式 (fixture / ヘルパ含む)
├── .github/workflows/      # GitHub Actions CI
├── CLAUDE.md / CLAUDE.ja.md # 貢献者・AI 向けのコーディングルール
├── LICENSE                 # MIT
└── README.md / README.ja.md
```

## テスト

`tests/` 配下の [bats](https://github.com/bats-core/bats-core) スイートが、ヘルプ表示、引数バリデーション、ローカルモック GitHub サーバを使った ZIP 経路 E2E、`convert-comments.sh` の往復、バナーの日英桁数チェックを網羅します。

```bash
brew install bats-core              # macOS
sudo apt-get install -y bats        # Debian / Ubuntu

bats tests/
```

CI は同じスイートを PHP 8.2 / 8.3 / 8.4 のマトリクスで実行します。詳細は [.github/workflows/ci.yml](./.github/workflows/ci.yml) を参照。

### URL の上書き

`install.php` は 2 つのオプション環境変数を読み込みます。テスト・内部ミラー・エアギャップ環境からの導入時に、スクリプト本体を編集することなくダウンロード先を切り替えられます。

| 環境変数 | デフォルト | 用途 |
| --- | --- | --- |
| `DIXLASE_API_LATEST_URL` | `https://api.github.com/repos/Dixlase/dixlase-core/releases/latest` | `{"tag_name": "vX.Y.Z"}` を返すエンドポイント |
| `DIXLASE_RELEASE_URL_BASE` | `https://github.com/Dixlase/dixlase-core/releases/download` | ベース URL。スクリプトが `/v<version>/dixlase-v<version>.zip` および `/v<version>/checksums.sha256` を付加して取得する |

## ライセンス

本インストーラー (`install.php` と関連スクリプト / 辞書) は [MIT ライセンス](./LICENSE) の下で配布されます。フォーク・改変・再配布に自由に利用できます。

Dixlase 本体のアプリケーションコードは別途 AGPL v3 ライセンスの下で配布されます。詳細は [Dixlase Core](https://github.com/Dixlase/dixlase-core) を参照してください。
