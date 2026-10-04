# Phase 8: 自動テスト

Phase 2〜7 で手作業で確認した挙動を、PHPUnit の自動テストにしました。

## 実行方法

```sh
docker compose exec app php artisan test

# 特定の方式だけ
docker compose exec app php artisan test --filter VersionLockTest

# テスト名の一覧（日本語のテスト名がそのまま仕様の一覧になる）
docker compose exec app vendor/bin/phpunit --testdox

# 実行順をランダムにする（テスト同士が影響し合っていないかの確認）
docker compose exec app vendor/bin/phpunit --order-by=random
```

| 項目 | 結果 |
|---|---|
| テスト数 | 49 件（224 アサーション） |
| 実行時間 | 約 4 秒 |
| ランダム順での実行 | 3 回とも全件成功 |

---

## テストの構成

```
tests/
├── Concerns/
│   └── InteractsWithReservations.php   # 「A が編集画面を開く」「B が保存する」を書くための補助
└── Feature/
    ├── ReservationCrudTest.php         # Phase 2: CRUD
    ├── LockModeTest.php                # Phase 3: 方式の切り替え
    ├── NoLockTest.php                  # Phase 3: 排他制御なし（Lost Update が起きる）
    ├── UpdatedAtLockTest.php           # Phase 4: updated_at 方式
    ├── VersionLockTest.php             # Phase 5: version 方式
    ├── PessimisticLockTest.php         # Phase 6: SELECT FOR UPDATE
    ├── EditLockTest.php                # Phase 7: 編集ロック
    └── EditLockWithExpiryTest.php      # Phase 7: 有効期限付き編集ロック
```

テスト名は日本語にしています。`--testdox` で一覧を出すと、そのまま各方式の仕様書として読めます。

---

## テスト用のデータベース

**SQLite ではなく MySQL**（`laravel_testing`）を使っています。`phpunit.xml` で設定しています。

このアプリの排他制御は、次のような MySQL の挙動に依存しているためです。

- `SELECT ... FOR UPDATE` の行ロックと、ロック待ちタイムアウト（エラー 1205）
- `NOW() + INTERVAL 60 SECOND` などの日時計算
- UPDATE の件数の意味（`ATTR_FOUND_ROWS`）

SQLite でテストが通っても、本番の MySQL で同じように動く保証になりません。

手動検証用の `laravel` データベースとは分けているので、テストを実行しても画面で使っているデータは変わりません。

---

## 同時実行をどうテストしたか

PHPUnit は 1 つのプロセスで順番にテストを実行するので、「2 人が同時に操作する」状況をそのままは作れません。
状況に応じて 4 つの方法を使い分けています。

### 方法 1: 操作の順番を決めて、交互に実行する

Lost Update や楽観的ロックの競合は、「本当に同時」である必要はありません。
「A が開く → B が開く → A が保存 → B が保存」という **順番** で起きる問題だからです。

```php
$formA = $this->openEdit($reservation, 'A');   // A が編集画面を開く
$formB = $this->openEdit($reservation, 'B');   // B が編集画面を開く

$this->save($reservation, $formA, ['number_of_people' => 4], 'A');
$this->save($reservation, $formB, ['status' => 'confirmed'], 'B')
    ->assertSessionHas('conflict', true);
```

- `openEdit()` は編集画面を実際に GET し、描画された HTML から入力欄と hidden の値を取り出します。
- `save()` はそのフォームの値に利用者の変更を加えて PUT します。
- hidden の値を DB から直接作らず HTML から取り出しているので、「編集画面が hidden を正しく出力しているか」も同時に検証できます。
- 操作者（A / B）は、リクエストのたびにセッションの操作者名を上書きして切り替えています。

### 方法 2: 時刻を止めて「同じ秒」を作る

updated_at 方式の弱点（同じ秒の更新を見逃す）は、手作業では 1 秒以内に操作する必要がありました。
テストでは Laravel の時刻を止めることで、毎回確実に再現できます。

```php
$this->travelTo('2026-10-01 10:00:00');   // 以降の now() はずっとこの時刻
```

`UpdatedAtLockTest` の「弱点 同じ秒の中で更新されると競合を見逃す」と、`VersionLockTest` の「同じ秒の中で更新されても競合を検知する」は、**まったく同じ手順** で結果が逆になります。2 つの方式の違いがテストで並んで見えます。

**注意:** 止められるのは Laravel（PHP）の時刻だけです。編集ロックの期限は MySQL の `NOW()` で判定しているので止められません。
期限切れの状態は、`locked_until` を過去の時刻に書き換えて作っています。

### 方法 3: 2 本目の DB 接続でロックを持ち続ける

悲観的ロックの「ロック待ちタイムアウト」と「通常の SELECT は待たされない」は、
**他のトランザクションがロックを持っている** 状況が必要です。

テストの中で同じ DB への 2 本目の接続を作り、そちらでロックを取ってコミットせずに持ち続けます。

```php
$other = DB::connection('other');
$other->beginTransaction();
$other->table('reservations')->where('id', $id)->lockForUpdate()->first();   // ロックを持ち続ける

DB::statement('SET SESSION innodb_lock_wait_timeout = 1');   // テストを速くするため 1 秒に
$this->save(...)->assertSessionHas('error', ...'ロック待ちタイムアウト'...);
```

### 方法 4: 別のプロセスで本当に同時に動かす

「ロック中は待たされ、解放された後に **最新の行** で判定する」ことは、方法 3 では確認できません。
2 本目の接続は同じプロセスの中にあるので、こちらが待っている間に向こうがコミットすることができないためです。

そこで、別の PHP プロセスを起動して「ロックを取る → 2 秒待つ → 人数を 8 にして version を上げる → コミット」を実行させます。

```
テスト本体                         別プロセス（A の保存処理の代わり）
                                   BEGIN
                                   SELECT ... FOR UPDATE   ← ロック取得
                                   （マーカーファイルを作る）
マーカーファイルを待つ
B の保存を送る
  SELECT ... FOR UPDATE ← 待たされる
                                   （2 秒待つ）
                                   UPDATE ... number_of_people = 8, version = version + 1
                                   COMMIT                  ← ロック解放
  最新の行（version = 2）を読む
  → 競合になる
```

アサーション:

- B の保存に 1 秒以上かかった（待たされた）。
- B は競合になった（待った後に最新の version を読んだ）。
- 人数は 8 のまま（A の変更が守られた）。

---

## テストが本当に検証できているかの確認

テストが成功しても、それが「正しい実装だから成功した」のか「何も検証していないから成功した」のかは分かりません。
そこで、悲観的ロックの実装から一時的に `->lockForUpdate()` を外して、テストが失敗するかを確認しました。

| テスト | lockForUpdate() を外した場合 |
|---|---|
| 保存時にトランザクションの中で SELECT FOR UPDATE を発行する | **失敗**（FOR UPDATE が発行されない） |
| 他のトランザクションがロック中なら待たされ 解放後の最新の行で判定する | **失敗**（待たずに古い version で判定し、保存が通る） |
| ロック待ちがタイムアウトしたら利用者にメッセージを返す | 成功 |
| その他 3 件 | 成功 |

タイムアウトのテストが成功し続けたのは正しい挙動です。
FOR UPDATE が無くても、UPDATE 文そのものが同じ行ロックを待つため、タイムアウトは起きます。

このように、わざと実装を壊してテストが失敗するかを確かめる方法を **ミューテーションテスト** と呼びます。

---

## 「弱点」のテスト

テスト名が「弱点」で始まるものは、**その方式の限界をそのまま記録したテスト** です。

| テスト | 記録している限界 |
|---|---|
| NoLockTest: 後から保存した人の内容で先の変更が消える LostUpdate | 排他制御なしでは Lost Update が起きる |
| UpdatedAtLockTest: 弱点 同じ秒の中で更新されると競合を見逃す | updated_at の秒精度 |
| PessimisticLockTest: version の確認を外すと FOR UPDATE だけでは LostUpdate を防げない | ロックは 1 リクエストの中でしか効かない |
| EditLockTest: 弱点 放置されたロックは強制解除するまで残る | 有効期限なしの編集ロック |
| EditLockTest: 弱点 削除は編集ロックを無視する | アプリのルールを確認しない処理からは守れない |

不具合を見逃しているわけではなく、「この方式ではこうなる」という性質を、他の方式と比べられるように残しています。
もし弱点を修正したら、このテストが失敗するので、テストも合わせて書き換えます。

---

## RefreshDatabase と DatabaseTruncation

| | RefreshDatabase | DatabaseTruncation |
|---|---|---|
| 仕組み | 各テストをトランザクションで囲み、最後にロールバック | テストの前にテーブルを空にする |
| 速さ | 速い | 遅い |
| テストのデータは別の接続から見えるか | **見えない**（コミットされない） | 見える |
| 使っているテスト | PessimisticLockTest 以外 | PessimisticLockTest |

悲観的ロックのテストでは、2 本目の接続や別プロセスから予約が見える必要があるので DatabaseTruncation を使っています。

DatabaseTruncation は「テストの前」にしか空にしないので、コミットしたデータが残り、後に実行される別のテストに影響しました（実装中に 3 件失敗）。
`tearDown()` でも予約テーブルを空にして解決しています。

---

## 自動テストにしていないもの

| 内容 | 理由 | 確認した場所 |
|---|---|---|
| 10 件の保存を同時に送ったときに 1 件だけ成功する | 実際の同時実行で確かめているのは MySQL の行ロックの保証で、アプリのロジックは方法 1 で検証済み | docs/phase5-version.md |
| 10 人が同時に編集画面を開いたときに 1 人だけロックを取れる | 同上 | docs/phase7-edit-lock.md |
| 有効期限を実際に 60 秒待って切れること | テストに 60 秒かかるため。期限の判定は方法 2 の注意のとおり locked_until の書き換えで検証 | docs/phase7-edit-lock.md |
| 画面の見た目、残り秒数のカウントダウン | JavaScript とブラウザの表示の確認になるため | ブラウザで手動確認 |
