# Laravel 排他制御検証アプリ

予約管理画面を題材に、**6 つの排他制御方式を画面から切り替えて、実際の挙動を比較・検証する** ための学習用アプリです。

複数の利用者が同じ予約を同時に編集したとき、何が起きるのか。
まず「排他制御なし」で **Lost Update**（先に保存した人の変更が消える現象）を再現し、
そのうえで各方式がどのタイミングで、どの SQL で、それをどう防ぐのかを確かめられます。

| # | 方式 | 一言でいうと |
|---|---|---|
| 1 | 排他制御なし | 何も確認しない。後から保存した人が上書きする |
| 2 | 楽観的ロック（updated_at） | 保存時に「開いた時点の updated_at と同じか」を確認する |
| 3 | 楽観的ロック（version） | 保存時に「開いた時点の version と同じか」を確認し、version を +1 する |
| 4 | 悲観的ロック（SELECT FOR UPDATE） | 保存処理の間だけ行ロックを取り、同時に保存しようとした人を待たせる |
| 5 | 編集ロック | 編集画面を開いた時点でロックを取り、他の人を編集画面に入れない |
| 6 | 有効期限付き編集ロック | 編集ロックに期限を付け、放置されたロックを他の人が奪えるようにする |

さらに、**画面のキャンセル処理（楽観的ロック）と、書き方の違うバッチが同時に動いた場合** の組み合わせも検証しています。
楽観的ロックで守っていても、バッチが後から古い前提のまま変更してしまう「後出し」を、どう防ぐのが最善かを
[docs/phase10-combinations.md](docs/phase10-combinations.md) にまとめています。

各方式の比較と検証結果は **[docs/results.md](docs/results.md)** にまとめています。

---

## 技術構成

| 項目 | 内容 |
|---|---|
| フレームワーク | Laravel 13 |
| 言語 | PHP 8.4 |
| データベース | MySQL 8.0（InnoDB / REPEATABLE READ） |
| 実行環境 | Docker Compose（Nginx + PHP-FPM + MySQL） |
| 画面 | Blade（JavaScript フレームワークや CSS フレームワークは使わない） |
| テスト | PHPUnit 12 |

`php artisan serve` ではなく Nginx + PHP-FPM にしているのは、複数のリクエストを本当に並行して処理させるためです。
理由と Docker 構成の詳細は [docs/docker.md](docs/docker.md) を参照してください。

---

## セットアップ

必要なもの: Docker Desktop（または Docker Engine + Docker Compose v2）。ホストに PHP や Composer は不要です。

```sh
git clone https://github.com/takahiro888/laravel-exclusive-control.git
cd laravel-exclusive-control

# 1. 環境変数ファイルを用意する
cp .env.example .env
printf 'UID=%s\nGID=%s\n' "$(id -u)" "$(id -g)" >> .env   # コンテナ内のユーザーをホストに合わせる
cp src/.env.example src/.env

# 2. イメージをビルドし、PHP のパッケージをインストールする
docker compose build
docker compose run --rm app composer install

# 3. 起動し、アプリケーションキーの生成とテーブル作成・初期データ投入を行う
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

ブラウザで **http://localhost:8080** を開くと予約一覧が表示されます。

| サービス | 接続先 |
|---|---|
| 画面 | http://localhost:8080 |
| MySQL（ホストから） | 127.0.0.1:33060 / laravel / secret |

> **Mac で `Mounts denied` が出る場合:**
> プロジェクトが `/Users` 以外（`/Applications` など）にあると、Docker Desktop がディレクトリを共有できません。
> Docker Desktop の Settings → Resources → File sharing にプロジェクトのパスを追加して、Apply & restart してください。
> 詳しくは [docs/docker.md のトラブルシューティング](docs/docker.md#5-トラブルシューティング) を参照してください。

---

## 使い方

### 画面

| 画面 | 内容 |
|---|---|
| ヘッダー | **排他制御方式** の切り替えと、**操作者** 名の設定 |
| 方式の表示 | 全画面の上部に、現在の方式とその説明を色付きで表示 |
| 予約一覧 / 詳細 | 予約の内容に加えて、version・updated_at・編集ロックの状態を表示（方式ごとの値の変化を追える） |
| 予約編集 | 方式ごとに必要な hidden 値を送る。競合時は「最新の内容」と「あなたの入力」を並べて表示 |

排他制御方式は **アプリ全体で 1 つ** です。どのブラウザで切り替えても、全員に反映されます。
A と B が別の方式で動くと比較にならないためです。

### 基本の検証手順

予約 #1（山田 太郎 / 2名 / 仮予約）を、利用者 A と B が同時に編集する手順です。

1. 2 つのブラウザを用意します。**別のブラウザ** か **通常ウィンドウとプライベートウィンドウ** を使います。
   - 操作者名はブラウザのセッションごとに保持されます。同じブラウザの別タブは同じ操作者になります。
   - ヘッダーの「操作者」を、それぞれ `A` と `B` に変更します（編集ロックの検証で必要）。
2. ヘッダーで検証したい方式に切り替えます。
3. A と B の両方で、予約 #1 の編集画面を開きます。
4. A が人数を **4名** に変えて保存します。
5. B がステータスを **確定** に変えて保存します。
6. 結果を確認します。

| 方式 | 手順 3〜5 の結果 |
|---|---|
| 排他制御なし | B の保存が成功し、A の 4名 が 2名 に戻る（Lost Update） |
| updated_at / version | B の保存が競合になり、最新の内容と B の入力が並んで表示される |
| SELECT FOR UPDATE | B の保存が競合になる（ロック取得後に version を確認しているため） |
| 編集ロック / 有効期限付き | 手順 3 で B が編集画面に入れない（「A さんが編集中」と表示） |

### 発行される SQL を見る

別のターミナルで次を実行しておくと、操作のたびに発行される SQL が流れます。

```sh
docker compose exec db tail -f /var/lib/mysql/general.log
```

2 列目の数字は接続 ID です。PHP-FPM はリクエストごとに新しい接続を作るので、同じ番号の行が 1 リクエスト分の SQL です。

### データを初期状態に戻す

```sh
docker compose exec app php artisan migrate:fresh --seed
```

方式の設定もキャッシュテーブルごと消えるので、「排他制御なし」に戻ります。

### 方式ごとの検証用機能

| 方式 | 機能 |
|---|---|
| SELECT FOR UPDATE | 編集画面の「検証用オプション」で、ロック取得後に待つ秒数（0 / 5 / 15 秒）と、version 確認の有無を選べる。タブ A で 5 秒を選んで保存し、すぐにタブ B で保存すると、B が待たされる様子を見られる |
| 編集ロック | 詳細画面の「強制解除」で、放置されたロックを外せる |
| 有効期限付き編集ロック | 期限は検証用に 60 秒。`src/.env` の `EDIT_LOCK_TTL_SECONDS` で変更できる |

### 画面の操作 × バッチの検証

予約詳細画面の「この予約をキャンセルする」ボタンは、方式の切り替えとは関係なく、常に version による楽観的ロックで処理します。
ステータスを一括で変更するバッチは、排他制御の書き方を選んで実行できます。

```sh
# 予約 1 を確定するバッチを、排他制御なしで、読み込み後に 15 秒待つ設定で実行する
docker compose exec app php artisan reservations:change-status pending confirmed --strategy=none --id=1 --pause=15
```

バッチが待っている間に画面でキャンセルすると、バッチの「後出し」でキャンセルが確定に戻る様子を再現できます。
`--strategy` には `none` / `state_guard` / `state_guard_version`（推奨） / `optimistic` / `pessimistic` を指定できます。

---

## テスト

```sh
docker compose exec app php artisan test
docker compose exec app vendor/bin/phpunit --testdox   # テスト名（日本語）の一覧
```

93 件のテストで、各方式の挙動と弱点、画面の操作とバッチの組み合わせを検証しています。
テストは手動検証用とは別のデータベース（`laravel_testing`）を使うので、画面のデータは変わりません。
同時実行のテスト方法は [docs/phase8-testing.md](docs/phase8-testing.md) を参照してください。

---

## ディレクトリ構成

```
.
├── docker-compose.yml
├── docker/                       # Nginx / PHP / MySQL の設定（docs/docker.md で解説）
├── docs/                         # 各 Phase の解説と検証結果
└── src/                          # Laravel 本体
    ├── app/
    │   ├── Enums/
    │   │   ├── LockMode.php                # 6 つの方式の定義
    │   │   ├── BatchStrategy.php           # バッチの排他制御の書き方（5 通り）
    │   │   └── ReservationStatus.php       # ステータスと状態遷移のルール
    │   ├── Console/Commands/ChangeReservationStatusCommand.php   # ステータス一括変更バッチ
    │   ├── Http/
    │   │   ├── Controllers/
    │   │   │   ├── ReservationController.php   # CRUD。更新は選択中の方式の Updater に任せる
    │   │   │   ├── LockModeController.php      # 方式の切り替え
    │   │   │   ├── OperatorController.php      # 操作者名の変更
    │   │   │   ├── EditLockController.php      # 編集ロックの解放・強制解除
    │   │   │   └── ReservationCancelController.php # キャンセル（楽観的ロック）
    │   │   └── Middleware/EnsureOperator.php   # 操作者名をセッションに用意
    │   ├── Models/Reservation.php
    │   └── Services/
    │       ├── LockModeSetting.php             # 現在の方式の保存・取得
    │       ├── EditLockService.php             # 編集ロックの取得・解放
    │       ├── CancelReservation.php           # キャンセル処理（version + 状態ガード）
    │       ├── Batch/ReservationStatusBatch.php  # バッチ本体（書き方 5 通り）
    │       └── ReservationUpdaters/            # ★ 方式ごとの更新処理
    │           ├── ReservationUpdater.php      #   共通インターフェース
    │           ├── NoLockUpdater.php           #   1. 排他制御なし
    │           ├── UpdatedAtLockUpdater.php    #   2. updated_at
    │           ├── VersionLockUpdater.php      #   3. version
    │           ├── PessimisticLockUpdater.php  #   4. SELECT FOR UPDATE
    │           ├── EditLockUpdater.php         #   5・6. 編集ロック
    │           └── ReservationUpdaterFactory.php
    ├── config/exclusive_control.php            # 編集ロックの有効期限
    ├── database/migrations/                    # reservations テーブル
    ├── resources/views/                        # Blade
    └── tests/Feature/                          # 方式ごとのテスト
```

方式ごとの違いは `src/app/Services/ReservationUpdaters/` に集めています。
`NoLockUpdater.php` と他のファイルを並べて読むと、各方式で何が増えたのかがそのまま分かります。
各クラスの先頭のコメントに、排他制御のタイミング、発行される SQL、競合時の挙動を書いています。

---

## ドキュメント

| ドキュメント | 内容 |
|---|---|
| [docs/results.md](docs/results.md) | **検証結果のまとめ**。6 方式の比較表、方式の選び方、検証で分かったこと |
| [docs/docker.md](docs/docker.md) | Docker 構成の解説、よく使うコマンド、SQL ログとロック状態の見方、トラブルシューティング |
| [docs/phase3-lost-update.md](docs/phase3-lost-update.md) | 排他制御なしで Lost Update を再現する |
| [docs/phase4-updated-at.md](docs/phase4-updated-at.md) | updated_at 方式と、秒精度などの弱点 |
| [docs/phase5-version.md](docs/phase5-version.md) | version 方式と、updated_at 方式との比較実験 |
| [docs/phase6-pessimistic-lock.md](docs/phase6-pessimistic-lock.md) | SELECT FOR UPDATE。ロック待ち、タイムアウト、FOR UPDATE だけでは防げない理由 |
| [docs/phase7-edit-lock.md](docs/phase7-edit-lock.md) | 編集ロックと有効期限付き編集ロック |
| [docs/phase8-testing.md](docs/phase8-testing.md) | 自動テストと、同時実行のテスト方法 |
| [docs/phase10-combinations.md](docs/phase10-combinations.md) | **画面の操作 × バッチの組み合わせ**。バッチの後出しを防ぐ最善の書き方、キャンセルを覆させない 3 層の守り方 |

---

## 注意

このアプリは学習・技術検証用です。次の点は実務向けではありません。

- ログイン機能が無く、操作者名は自由に変更できる。編集ロックの持ち主は名前で判定している。
- 方式の切り替えや強制解除を、誰でも実行できる。
- MySQL の General Query Log を常に有効にしている。
- 編集ロックを、編集画面を開く GET リクエストで取得している（他の方式と同じ操作で比較するため）。
