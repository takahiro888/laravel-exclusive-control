# Phase 6: SELECT FOR UPDATE を利用した悲観的ロック

## 考え方

**悲観的ロック** は「競合は起きるもの」と悲観的に考え、**更新する前にロックを取って、他の人を待たせる** 方式です。

`SELECT ... FOR UPDATE` は、読み取った行に排他ロック（行ロック）を取ります。
ロックはトランザクションがコミット（またはロールバック）されるまで保持されます。
その間、他のトランザクションが同じ行に `FOR UPDATE` や `UPDATE` をしようとすると、ロックが外れるまで待たされます。

---

## なぜこの実装が必要なのか

楽観的ロック（Phase 4・5）は、競合したら **後から保存した人が負ける** 方式でした。

悲観的ロックは、**同時に処理しようとした人を順番に並ばせる** 方式です。
「在庫を 1 減らす」「残席を確認してから予約する」のように、
**読んだ値をもとに計算して書き込む** 処理を、1 つのリクエストの中で安全に行うのが本来の使いどころです。

この Phase では、予約編集の保存処理に適用して、どこまで守れて、どこから守れないのかを確かめます。

---

## 排他制御のタイミング

| タイミング | 何が起きるか |
|---|---|
| 編集画面を開く | 何もしない（ロックは取らない）。hidden に version を埋め込む |
| 編集中 | 何もしない |
| **保存リクエストの `SELECT ... FOR UPDATE`** | **ここでロックを取る**。他の人がロック中なら、ここで待つ |
| 保存リクエストの `COMMIT` | ここでロックを外す |

ロックが効いているのは、**保存リクエストの中の、FOR UPDATE から COMMIT までの間だけ** です。

---

## 発行される SQL

```sql
START TRANSACTION
select * from `reservations` where `reservations`.`id` = 1 limit 1 for update   -- 行ロックを取得
-- （PHP で version を確認する）
update `reservations` set `number_of_people` = '4', `version` = 2, `reservations`.`updated_at` = '...' where `id` = 1
COMMIT                                                                           -- 行ロックを解放
```

楽観的ロックとの違い:

- UPDATE の WHERE 句は `id = 1` だけです。確認は WHERE 句ではなく、ロックを取った後の PHP の if で行います。
- version は SQL の式（`version + 1`）ではなく、PHP で計算した値（`2`）を書き込んでいます。

どちらも、ロックを持っている間は他のトランザクションがこの行を更新できないので安全です。
Phase 4 で「PHP の if で確認してから UPDATE するのは危険」と書いたのは、ロックが無い場合の話です。

---

## 実験

画面上部で「悲観的ロック」に切り替えると、編集画面に検証用オプションが出ます。

| オプション | 用途 |
|---|---|
| ロック取得後に待つ秒数 | 0 / 5 / 15 秒。ロックを持ったまま待ち、別のタブの保存が待たされる様子を見る |
| ロック取得後に version を確認する | 外すと FOR UPDATE だけになる |

ブラウザで試す場合は、タブ A で「5 秒」を選んで保存し、すぐにタブ B で保存します。
タブ B の読み込み中の表示が、A の保存が終わるまで続きます。

### 実験 1: ロック待ち

A がロックを 5 秒持っている間に、B が保存しました。A と B は同じ時点（version = 1）で編集画面を開いています。

```
時刻          接続ID  SQL
19:40:01.634  2342  START TRANSACTION                                    ← A
19:40:01.636  2342  select * from `reservations` where ... for update    ← A がロックを取得
19:40:02.627  2343  START TRANSACTION                                    ← B
19:40:02.628  2343  select * from `reservations` where ... for update    ← B はここで待たされる
     （A は 5 秒待機中）
19:40:06.645  2342  update `reservations` set `number_of_people` = '4', `version` = 2, ... where `id` = 1
19:40:06.650  2342  COMMIT                                               ← A がロックを解放
19:40:06.657  2343  ROLLBACK                                             ← B はロックを取得し、version が 2 なので競合
```

| | かかった時間 | 結果 |
|---|---|---|
| A | 5.05 秒 | 成功（version 1 → 2） |
| B | **4.08 秒**（ほぼ待ち時間） | 競合で止まる |

B の FOR UPDATE は、A の COMMIT の直後まで約 4 秒待たされました。
ロックが取れた時点で B は **最新の行（version = 2）** を読むので、B が開いた時点の version 1 と違うことに気づけます。

#### 待っている間のロックの状態

B が待っている間に `performance_schema.data_locks` を見た結果です。

```
trx   INDEX_NAME  LOCK_TYPE  LOCK_MODE      LOCK_STATUS  LOCK_DATA
7094  NULL        TABLE      IX             GRANTED      NULL
7095  NULL        TABLE      IX             GRANTED      NULL
7094  PRIMARY     RECORD     X,REC_NOT_GAP  GRANTED      1        ← A が id=1 の行ロックを持っている
7095  PRIMARY     RECORD     X,REC_NOT_GAP  WAITING      1        ← B が同じ行ロックを待っている

waiting_trx  blocking_trx
7095         7094                                                 ← B は A に待たされている
```

| 列の値 | 意味 |
|---|---|
| `TABLE` / `IX` | テーブルに対する意図ロック。「このテーブルのどこかの行に排他ロックを取る予定」という印。IX 同士はぶつからない |
| `RECORD` / `X` | 行に対する排他ロック。他のトランザクションは FOR UPDATE も UPDATE もできない |
| `REC_NOT_GAP` | 行そのものだけをロックし、行と行の隙間（ギャップ）はロックしていない。主キーの等価検索なのでこうなる |
| `PRIMARY` / `1` | 主キーのインデックス上の id = 1 の行 |

確認するコマンド:

```sh
docker compose exec db mysql -uroot -proot -e "
  SELECT ENGINE_TRANSACTION_ID, INDEX_NAME, LOCK_TYPE, LOCK_MODE, LOCK_STATUS, LOCK_DATA
  FROM performance_schema.data_locks WHERE OBJECT_NAME = 'reservations';
  SELECT REQUESTING_ENGINE_TRANSACTION_ID, BLOCKING_ENGINE_TRANSACTION_ID
  FROM performance_schema.data_lock_waits;"
```

#### 通常の SELECT は待たされない

A がロックを持っている間に、C が詳細画面を開きました。

| | 結果 |
|---|---|
| かかった時間 | 0.09 秒（待たされない） |
| 表示された人数 | 2 名（A がまだコミットしていないので、変更前の値） |

`FOR UPDATE` が付いていない通常の SELECT はロックを取りません。
InnoDB の MVCC（多版型同時実行制御）により、**最後にコミットされた時点の値** をロックなしで読みます。
悲観的ロックで待たされるのは、**同じ行をロックしようとする処理（FOR UPDATE / UPDATE / DELETE）だけ** です。

### 実験 2: ロック待ちタイムアウト

A がロックを 15 秒持っている間に、B が保存しました。

```
19:40:18.695  2358  START TRANSACTION                                  ← A
19:40:18.695  2358  select ... for update                              ← A がロックを取得（15 秒保持）
19:40:19.754  2360  START TRANSACTION                                  ← B
19:40:19.755  2360  select ... for update                              ← B は待たされる
19:40:29.804  2360  ROLLBACK                                           ← B は 10 秒でタイムアウト
19:40:33.704  2358  update `reservations` set `number_of_people` = '4', `version` = 2, ...
19:40:33.721  2358  COMMIT                                             ← A は成功
```

| | かかった時間 | 結果 |
|---|---|---|
| A | 15.05 秒 | 成功 |
| B | **10.10 秒** | ロック待ちタイムアウト |

`docker/mysql/my.cnf` で `innodb_lock_wait_timeout = 10` にしているので、B は 10 秒で MySQL にエラー 1205 を返されました。
アプリはこのエラーを捕まえて、エラー画面ではなく次のメッセージを出します。

> 他の人がこの予約を保存処理中のため、待ちきれずに中断しました（ロック待ちタイムアウト）。少し待ってから、もう一度保存してください。

**注意:** デフォルトの 50 秒のままだと、利用者は最大 50 秒間、画面が固まったように見えます。
悲観的ロックでは「ロックを持ったまま時間のかかる処理をしない」ことが重要です。
外部 API の呼び出しやメール送信などを、トランザクションの中で行わないようにします。

### 実験 3: FOR UPDATE だけでは Lost Update を防げない

「ロック取得後に version を確認する」を外して、Phase 3 と同じ手順を実行しました。A と B は同じ時点で編集画面を開いています。

| version 確認 | 保存の順番 | B の保存 | 最終的な人数 | 結果 |
|---|---|---|---|---|
| なし | A → B（順番に） | 成功 | 2 | **Lost Update** |
| なし | A のロック中に B（同時に） | 4 秒待ってから成功 | 2 | **Lost Update** |
| あり | A → B（順番に） | 競合で止まる | 4 | 守られた |
| あり | A のロック中に B（同時に） | 4 秒待ってから競合で止まる | 4 | 守られた |

version 確認なしで同時に保存したときの UPDATE です。

```sql
-- A: ロックを取って 5 秒後に更新
update `reservations` set `number_of_people` = '4', `version` = 2, ... where `id` = 1
-- B: A のコミットまで待たされた後、A の変更を上書き
update `reservations` set `number_of_people` = '2', `status` = 'confirmed', `version` = 3, ... where `id` = 1
```

B は A のコミットまで待たされましたが、待った後に **A の変更を上書き** しました。

#### なぜ防げないのか

```
[編集画面を開くリクエスト]       [保存リクエスト]
   接続 X                        接続 Y
   SELECT（ロックなし）          BEGIN
   レスポンスを返す → 接続終了     SELECT ... FOR UPDATE  ← ロックはここから
                                 UPDATE
         ↑                       COMMIT                 ← ここまで
   この間（利用者が編集している時間）
   は、どのロックも効いていない
```

- ロックが効くのは、1 つのトランザクション（= 1 つの保存リクエスト）の中だけです。
- 利用者が編集画面を開いてから保存するまでの間、DB はその利用者のことを覚えていません。
- FOR UPDATE がしてくれるのは「保存処理を 1 件ずつ順番に実行する」ことだけです。
- B の保存処理は、A の後に順番どおり実行されました。しかし B のフォームの中身は、A の変更前に作られた古い内容です。

Web アプリの「画面を開いてから保存するまで」を守るには、FOR UPDATE だけでは足りません。
編集開始時の version を確認する（楽観的ロックとの組み合わせ）か、Phase 7 の編集ロックが必要です。

---

## 実装のポイント

### トランザクションが必須

```php
DB::transaction(function () {
    $locked = Reservation::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    // ...
    $locked->save();
});
```

MySQL は、トランザクションの外では 1 文ごとに自動でコミットします（autocommit）。
トランザクションの外で FOR UPDATE を実行すると、SELECT が終わった瞬間にロックが外れ、意味がありません。

### ルートモデルバインディングで取得した行を使わず、ロックを取って読み直す

コントローラに渡される `$reservation` は、ロックを取る前に通常の SELECT で読んだものです。
ロック待ちをしている間に他の人が更新すると、この値は古くなります。

実験 1 では、B の `$reservation` は version = 1 のままでした。
FOR UPDATE で読み直したことで、最新の version = 2 に気づけています。

FOR UPDATE は、分離レベル REPEATABLE READ でも **最新のコミット済みの行** を読みます（ロッキングリード）。
通常の SELECT は、トランザクション開始時点の値を読み続けます（一貫性読み取り）。

### ロック待ちタイムアウトを利用者に分かる形で伝える

MySQL のエラー 1205（Lock wait timeout exceeded）を捕まえて、競合と同じ形でメッセージを出しています。
捕まえないと、500 エラーの画面になります。

---

## 他の方式との違い

| | updated_at / version（楽観的） | SELECT FOR UPDATE（悲観的） |
|---|---|---|
| ロックを取るか | アプリでは取らない（UPDATE 文の内部でだけ一瞬取る） | 保存トランザクションの間、明示的に取る |
| 同時に保存した場合 | 後の人はすぐに競合エラー | 後の人は **待たされ**、その後に処理される |
| 待ち時間 | なし | 先の人のトランザクションの長さ。最大 innodb_lock_wait_timeout |
| 確認の方法 | UPDATE の WHERE 句 | ロック取得後の PHP の if（ロック中なので安全） |
| 画面を開いてからの変更 | 検知できる | **単独では検知できない**。version 確認との併用が必要 |
| 向いている処理 | 画面をまたぐ編集 | 1 リクエストの中の「読んで、計算して、書く」処理 |

悲観的ロックのリスク:

- ロックを持つ時間が長いと、他の利用者が待たされ、タイムアウトが増えます。
- 複数の行を違う順番でロックすると、デッドロックが起きます（MySQL がどちらかを自動でロールバックします）。
