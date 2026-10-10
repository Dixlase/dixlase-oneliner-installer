# トラブルシュート

`curl -sS https://install.dixlase.net | php` を実行したときに遭遇しやすい失敗、その本当の意味、修正手順を整理します。

## 「Could not fetch release information from GitHub」

```
▸ Fetching latest release information
  ✗ Could not fetch release information from GitHub. Check your network connection.
```

このリクエストにトークンは不要です (リポジトリは public)。考えられる原因:

1. **`api.github.com` に接続できない**、または GitHub の障害。ホストから HTTPS で GitHub に届くか確認してください。
2. **GitHub の匿名 API の上限を使い切った**。匿名のリクエストは IP アドレスごとに 1 時間 60 回までなので、IP を共有している環境 (CI・社内ネットワーク・共有ホスティング) では使い切ることがあります。1 時間待つか、トークンを渡してください ([オプション: GitHub トークン](../../README.ja.md#オプション-github-トークン) を参照)。
3. **トークンを設定しているが、無効または期限切れ**。GitHub は匿名扱いにせずリクエストを拒否します。トークンを直すか、外してください (トークンは任意です)。

`--method=auto` (既定) では、Composer がインストールされていれば `composer create-project` に切り替えます。

## 「Could not find package dixlase/dixlase-core with stability stable」

Composer 経路で解決時に失敗:

```
In CreateProjectCommand.php line 424:
  Could not find package dixlase/dixlase-core with stability stable.
```

`dixlase/dixlase-core` は Packagist に無いので、Composer はインストーラが `--repository` で渡す GitHub リポジトリからしか見つけられません。原因は次のいずれか:

- **古いインストーラを使っている**。インストーラ 0.1.1 以前は、トークンがある時だけリポジトリを渡していたので、匿名の `--method=composer` は必ずここで止まりました。`https://install.dixlase.net` から最新のインストーラを取得するか、`--method=zip` を使ってください。
- **`--version` にタグの無い版を指定した** (打ち間違いなど)。タグは <https://github.com/Dixlase/dixlase-core/releases> で確認できます。

## 「composer create-project failed」/「Could not authenticate against github.com」

Composer が Dixlase Core のリポジトリを読めなかった状態です。インストーラはよくある原因として、`github.com` に接続できない・GitHub の障害やレート制限・Composer が表示した PHP の要件を挙げます。トークンが無いとき、インストーラは Composer に `git` でリポジトリを読ませます (匿名 API の上限に数えられない) — ただし `git` がインストールされている場合だけです。`git` が無いと Composer は GitHub API を使い、「Could not authenticate against github.com」はたいてい匿名の上限を使い切ったことを意味します。`git` を入れる・1 時間待つ・トークンを渡す、のいずれかで直ります。いちばん簡単なのは `--method=zip` です — リリース ZIP はビルド済みで、依存の解決が要りません。

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
curl -sS https://install.dixlase.net | php
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
curl -sS https://install.dixlase.net | php -- --method=zip
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
