# Dixlase Install Scripts

Dixlase CMS のワンライナーインストールスクリプト群。

## プロジェクト概要

- `curl -sS https://install.dixlase.com | php` で Dixlase をサーバーにインストール
- PHP 単体で動作（外部依存なし）
- GitHub Releases からの ZIP ダウンロード・展開・環境構築を自動化

## 技術スタック

- PHP 8.2+ （スクリプト実行要件）
- 対象: Dixlase CMS（Laravel 12 ベース）

## 規約

### コードコメント言語
- コードコメントと PHPDoc ブロックは全て日本語で記述する

### ライセンスヘッダー
- PHP ファイルには AGPL v3 ライセンスヘッダーを PHPDoc ブロック形式で挿入する

### Git コミットメッセージ
- コミットメッセージに `Co-Authored-By` 行を含めない
- conventional commit 形式を使用する（例: `feat:`, `fix:`, `refactor:`）
- コミットメッセージは**日英バイリンガル形式**で記述する
- タイトルは英語・日本語を連続して冒頭に配置し、その後に英語の箇条書き、`----` 区切り、日本語の箇条書きの順
- 日本語タイトル行には conventional commit プレフィックス（`feat:` / `fix:` 等）を**付けない**
- 例:
  ```
  feat: add user profile page
  ユーザープロフィールページを追加

  - Add ProfileController with show/edit actions
  - Create profile Blade views with avatar upload

  ----

  - ProfileControllerにshow/editアクションを追加
  - アバターアップロード付きプロフィールBladeビューを作成
  ```

### PHP スタイル
- 制御構文では単一行でも常に波括弧を使用する
- メソッドと関数には常に明示的な戻り値型を宣言する
- インラインコメントより PHPDoc ブロックを優先する

### 応答
- 説明は簡潔に — 自明な詳細の説明ではなく重要な点に集中する
