# Dixlase Oneliner Installer — Coding Rules

For English, see [CLAUDE.md](./CLAUDE.md).

このリポジトリで作業するすべての貢献者 (人間・AI 双方) が従うべきルール。

## プロジェクト概要

- ワンライナーインストーラ: `curl -sS https://install.dixlase.com | php`
- 単一ファイルの PHP スクリプト (`install.php`)。PHP 8.2+ 以外の実行時依存はなし
- 2 つの配布経路をサポート:
  - `composer create-project dixlase/dixlase-core` (Composer が PATH にあるとき優先)
  - GitHub Releases ZIP フォールバック (Composer がないとき)
- 対象: Dixlase CMS (Laravel 12 ベース)。リポジトリは `Dixlase/dixlase-core`

## コミットメッセージ規約

Dixlase プロジェクト全体で統一されたコミットメッセージ規約に従う。

### 必須ルール

1. **`Co-Authored-By:` 行を付けない**
   - Claude / `noreply@anthropic.com` を含む一切の `Co-Authored-By` 行を禁止する。

2. **Conventional Commits プレフィックスを使用する**
   - `feat:` — 新機能
   - `fix:` — バグ修正
   - `refactor:` — 動作を変えないコード改善
   - `docs:` — ドキュメントのみの変更
   - `test:` — テストの追加・更新
   - `chore:` — ツーリング、依存関係、その他のメンテナンス

3. **バイリンガル形式 (英 / 日)**
   - Subject: 1 行目に英語タイトル、2 行目に日本語タイトル (日本語タイトル行には Conventional Commits プレフィックスを **付けない**)
   - 本文: 英語の箇条書き → `----` 区切り → 日本語の箇条書きの順

### 雛形

```
feat: add user profile page
ユーザープロフィールページを追加

- Add ProfileController with show/edit actions
- Create profile Blade views with avatar upload

----

- ProfileControllerにshow/editアクションを追加
- アバターアップロード付きプロフィールBladeビューを作成
```

## ファイル編集の方針

- `install.php` は PHP 8.2 と stdlib 拡張のみで動作させる (`DIXLASE_REQUIRED_EXTENSIONS` に列挙されたもの)。
- 補助シェルスクリプト (例: `convert-comments.sh`) は POSIX 準拠の `bash` で書き、macOS / Linux 両方で動作させる。
- `sed -i` などの BSD/GNU 差異が出るコマンドは、両環境で動く形 (例: `sed -i.bak ... && rm *.bak`) を選ぶ。
- ハードコードされたパス・個人マシン依存の値は禁止する (公開配布物のため)。

## PHP スタイル

- 制御構文では単一行でも常に波括弧を使用する。
- メソッドと関数には常に明示的な戻り値型を宣言する。
- 自明でないヘルパには、インラインコメントより PHPDoc ブロックを優先する。
- すべての PHP ソースファイルの先頭に AGPL v3 ライセンスヘッダーを PHPDoc ブロック形式で挿入する。

## ソース内コメント・文字列の言語

- **ソースコード上のコメントと UI 文字列 (`info()` / `warn()` / `error()` / `step()` / ヘルプ / バナー / プロンプト) はすべて英語で書く** (デフォルトロケール)。日本語コメント・日本語 UI 文字列を直接書かない。
- 日本語が必要な場面 (例外):
  - `README.ja.md` / `CLAUDE.ja.md` などの `*.ja.md` ドキュメント
  - `lang/ja/` 配下の翻訳辞書 (本ディレクトリは日本語の正規ソース)
  - コミットメッセージのバイリンガル併記 (上記規約参照)
- 既存ファイルを編集する際に日本語コメント・日本語 UI 文字列を見つけたら:
  1. 英語に置き換える
  2. 該当の英語テキストと日本語の対訳を `lang/ja/<source-path>.tsv` に追記する
  3. `lang/en/<source-path>.tsv` にも identity (英→英) を追記する (`./convert-comments.sh` 経由で再生成可)

## 翻訳ライブラリ (`lang/{en,ja}/`)

各ソースファイル (現状は `install.php`、今後追加されるスクリプト) は、対応する翻訳辞書を `lang/<locale>/<source-path>.tsv` に持つ。

### フォーマット

タブ区切りの 1 行 1 ペア:

```
<english-text>	<locale-text>
```

- 1 行に source 側テキストと target ロケール側テキストを `\t` で区切って記述する
- `lang/en/<file>.tsv` は identity (col1 == col2)。`lang/ja/<file>.tsv` は英→日の対訳
- 空行・カラム数不足の行は無視される
- コメント行 (`#` 始まり) も普通のエントリ。辞書ファイル内にメタコメントは入れない

### キーの作り方

- **インラインコメント** (`// Text` 形式): 行頭インデントを含めず `// Text` をキーにする (sed の部分一致が行頭インデントを保持するため)
- **PHPDoc 行** (` * Text` 形式): 先頭の ` * ` も含めて ` * Text` をキーにする
- **文字列リテラル** (`'…'` / `"…"`): 引用符内のテキストをそのままキーにする (前後の空白も含めて表示用整形を保つ)
- **置換衝突への注意**: キーは十分に固有性を持たせる。`'     The '` のような短い断片が逆変換時に他箇所と false-match する場合は、周辺コードを含めた長いキー (例: `'     The ' . bold('Installation Wizard') . ' will guide you through:'`) にまとめる。

### 一括変換スクリプト

`./convert-comments.sh` は辞書を読んでソースを書き換える bash スクリプト:

```bash
./convert-comments.sh ja                # 全ファイル: 英 -> 日
./convert-comments.sh ja install.php    # 単一ファイル: 英 -> 日
./convert-comments.sh ja --reverse      # 全ファイル: 日 -> 英 (復元)
```

辞書を編集したら必ず往復テスト (`ja` → `ja --reverse` で原本に戻ること) と `php -l install.php` で検証する。

## 応答の流儀

- 説明は簡潔に。自明な詳細ではなく重要な点に集中する。
