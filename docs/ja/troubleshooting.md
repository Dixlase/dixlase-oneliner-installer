# トラブルシュート

`curl -sS https://install.dixlase.net | php` を実行したときに遭遇しやすい失敗、その本当の意味、修正手順を整理します。

## 「Could not fetch release information from GitHub」

```
▸ Fetching latest release information
  ✗ Could not fetch release information from GitHub. Check your network connection.
```

3 つの原因が考えられます。どれかによって対応が異なります:

1. **GitHub トークンを送っていない、かつ Dixlase Core リポジトリが private のまま**。`php` の前に `GITHUB_TOKEN=github_pat_xxx` を付けて再実行してください。トークン作成手順は [README.ja.md のプライベートリポジトリ節](../../README.ja.md#プライベートリポジトリ) を参照。
2. **トークンは設定済みだが `Dixlase/dixlase-core` へのアクセス権が無い**。トークンの「Repository access」に該当リポを追加し、**Contents: Read-only** を付与してください。
3. **リポジトリにまだ GitHub Release が 1 つも作られていない**。その場合 API は 404 を返します。composer 経路 (主配布経路) はリリース不要なので、Composer がローカルにあればそちらで動きます。明示的に composer 経路を強制する場合は `--method=composer` を追加してください。

## 「Could not find package dixlase/dixlase-core with stability stable」

Composer 経路で解決時に失敗:

```
In CreateProjectCommand.php line 424:
  Could not find package dixlase/dixlase-core with stability stable.
```

原因は次のいずれか:

- **Dixlase Core の `composer.json` が `"name": "dixlase/dixlase-core"` になっていない**。上流のリネーム PR がマージされれば自然に解消します。
- **リポジトリにタグ付きリリースが無い**ので composer が安定版を見つけられない。インストーラはトークンが設定されているとき `--stability=dev` を渡してこれを回避します。それでも出る場合はトークンが検出されていないので、`GITHUB_TOKEN=` が `php` と同じ行にあるか再確認してください。

## 「Project directory ... is not empty」

```
In CreateProjectCommand.php line 371:
  Project directory "/path/to/install" is not empty.
```

Composer は既存ファイルがあるディレクトリへの上書きを拒否します。直前の失敗で残骸が残っているのが典型的な原因。掃除して再試行:

```bash
cd ~
rm -rf /path/to/install
mkdir -p /path/to/install
cd /path/to/install
curl -sS https://install.dixlase.net | GITHUB_TOKEN=... php
```

## 「stream_context_create(): Argument #1 must be ... cannot access protected method」

```
[TypeError]
stream_context_create(): Argument #1 ($options) must be an array with valid
callbacks as values, cannot access protected method
Composer\Util\RemoteFilesystem::callbackGet()
```

Composer 2.7.x のバグで、PHP 8.4 以降で発火します。Composer 2.8+ で修正済みです。以下を実行:

```bash
sudo composer self-update
composer --version    # 2.8.x または 2.9.x になっていれば OK
```

その後、ディレクトリを掃除してインストーラを再実行。

## 「The lock file is not up to date with the latest changes in composer.json」

```
Warning: The lock file is not up to date with the latest changes in composer.json.
  - Required package "composer/installers" is not present in the lock file.
  - Required package "dixlase/dixlase-onepage" is not present in the lock file.
```

Dixlase Core 側で `composer.json` を変更したのに `composer.lock` を再生成せず push してしまっている状態。上流の問題なので <https://github.com/Dixlase/dixlase-core/issues> に Issue を立ててください。当座の回避策として ZIP fallback を明示:

```bash
curl -sS https://install.dixlase.net | GITHUB_TOKEN=... php -- --method=zip
```

## 「Failed to extract: ... ???something.blade.php」

```
Failed to extract dixlase/dixlase-core: (50) /usr/bin/unzip ...
.../???security-notifications.blade.php: write error (disk full?)
The archive may contain identical file names with different capitalization
```

Dixlase Core のアーカイブ内に case-insensitive FS (macOS APFS 典型) で衝突するファイル名が存在しています。Composer は自動的に `ZipArchive` フォールバックに切替えるので、**この警告は致命ではなくインストールは継続**します。根本的な重複ファイル名は dixlase-core 側で解消すべきで、上流に Issue を立てるのが筋です。

## 「php_network_getaddresses: getaddrinfo for mysql failed」

```
WARN  SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for mysql failed
```

`.env` がまだ `DB_HOST=mysql` (Docker 既定のホスト名) のまま。インストール途中の `migrate --graceful` は DB 到達不可なら警告で済ませますが、ブラウザでウィザードを開く前に必ず修正が必要です。`.env` を実際の DB ホスト (例: ローカル DB なら `127.0.0.1`、レンタル先の DB ホスト名等) に書き換えてください。

## 「Composer install failed (exit code 4)」

Composer 2 は `--no-interaction` 下で lock ファイル不整合を fatal 扱いにします。上の「lock ファイル out of date」と同根です。対応:

1. 上流に `composer.lock` 再生成を依頼
2. `--method=zip` で composer の lock ベース install を回避
3. 自分で手元にクローン → `composer update` を済ませた状態のディレクトリを使う

## シェル履歴にトークンを残してしまった

うっかりトークンをコマンドラインに直書きしてしまった場合、**直ちに revoke** してください: <https://github.com/settings/tokens?type=beta>。漏洩したトークンは以下に残ります:

- シェル履歴 (`~/.bash_history`、`~/.zsh_history`)
- 共有したログ全般 (チャット転写、GitHub Issue、ペーストサイト)

今後は履歴に残さないため、トークンは先に環境変数として読み込ませる形にしましょう:

```bash
read -rs GITHUB_TOKEN && export GITHUB_TOKEN
# (トークンを貼り付けて Enter — 入力は表示されません)
curl -sS https://install.dixlase.net | php
unset GITHUB_TOKEN
```

macOS なら Keychain に格納して取り出す手も:

```bash
# 1 度だけ保存
security add-generic-password -s dixlase-installer -a "$USER" -w

# 実行 (env をパイプ右辺に置く。token は php に渡る、curl には渡らない)
curl -sS https://install.dixlase.net | env GITHUB_TOKEN="$(security find-generic-password -s dixlase-installer -w)" php
```

> ⚠️ 素朴な形 `GITHUB_TOKEN=$(security ...) curl ... | php` は **動きません**。`VAR=val` の一時代入はパイプ**左辺の `curl` にしか渡らず**、右辺の `php` には届きません。`env GITHUB_TOKEN=...` を `php` の前に置く (上記の形)、または `export GITHUB_TOKEN=$(security ...)` 後に実行 + 後で `unset GITHUB_TOKEN`、のどちらかを使ってください。

## それでも詰まったとき

- `--non-interactive --yes` を外して対話モードで 1 ステップずつ確認
- `curl -sS https://install.dixlase.net > install.php` で取得後、`php install.php --help` で全フラグを確認
- フル出力 (トークンは伏字に) を添えて <https://github.com/Dixlase/dixlase-oneliner-installer/issues> に Issue を立てる
