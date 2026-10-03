# Phase 4: updated_at を利用した楽観的ロック

## 考え方

**楽観的ロック** は「競合はめったに起きない」と楽観的に考える方式です。

- 編集中はロックを取りません。誰でも同時に編集画面を開けます。
- その代わり **保存の瞬間に**「編集画面を開いたときから、行が変わっていないか」を確認します。
- 変わっていたら保存を拒否し、利用者に「他の人が先に更新した」と伝えます。

「行が変わったかどうか」の目印として、更新のたびに必ず変わる `updated_at` を使うのが updated_at 方式です。

---

## なぜこの実装が必要なのか

Phase 3 で見たとおり、排他制御なしの UPDATE は `where id = 1` だけなので、
B が編集画面を開いた後に A が更新していても、B の古い内容で上書きしてしまいます。

問題は「B が **何を見て** 編集したのか」をサーバーが知らないことです。
そこで、編集画面を開いた時点の `updated_at` をフォームに埋め込み、保存時に送り返してもらいます。

```html
<input type="hidden" name="original_updated_at" value="2026-10-03 17:04:09">
```

---

## 排他制御のタイミング

| タイミング | 何が起きるか |
|---|---|
| 編集画面を開く | 何もしない（ロックを取らない）。hidden に updated_at を埋め込むだけ |
| 編集中 | 何もしない。他の人も自由に編集画面を開ける |
| **保存（UPDATE）** | **ここだけで確認する**。WHERE 句に `updated_at = 開いた時点の値` を加える |

---

## 発行される SQL

### 正常に更新できる場合

```sql
update `reservations`
   set `customer_name` = '山田 太郎', `number_of_people` = '4',
       `reservation_date` = '2026-10-10 19:00:00', `status` = 'pending',
       `reservations`.`updated_at` = '2026-10-03 17:04:11'
 where `reservations`.`id` = 1
   and `updated_at` = '2026-10-03 17:04:09'     -- ← 編集画面を開いた時点の値
```

WHERE 句に一致する行が 1 件あり、更新されます。`updated_at` は現在時刻に変わります。

### 競合した場合

A と B が同じ時点（17:04:09）で編集画面を開き、A が先に保存した後に B が保存したときの実際のログです。

```
時刻          接続ID  SQL
17:04:09.618  622  select * from `reservations` where `id` = '1' limit 1     ← A が編集画面を開く
17:04:10.693  624  select * from `reservations` where `id` = '1' limit 1     ← B が編集画面を開く
17:04:11.795  625  update ... `number_of_people` = '4', ... `updated_at` = '2026-10-03 17:04:11'
                   where `reservations`.`id` = 1 and `updated_at` = '2026-10-03 17:04:09'   ← A: 1件更新
17:04:12.985  627  update ... `number_of_people` = '2', `status` = 'confirmed', ... `updated_at` = '2026-10-03 17:04:12'
                   where `reservations`.`id` = 1 and `updated_at` = '2026-10-03 17:04:09'   ← B: 0件更新
```

A の更新で `updated_at` は 17:04:11 に変わりました。
B は 17:04:09 を条件にしているので、一致する行が無く **0 件更新** になります。

---

## 競合した場合に何が起こるか

1. 更新件数が 0 件なので、`ReservationConflictException` を投げます。
2. コントローラが受け取り、入力内容を残したまま編集画面に戻します。
3. 編集画面には「最新の内容（DB）」と「あなたの入力」を並べた表が出ます。違う項目には色が付きます。

実際に B に表示された内容です。

| 項目 | 最新の内容（DB） | あなたの入力 |
|---|---|---|
| 人数 | 4 | 2 |
| ステータス | pending | confirmed |

B は「A が人数を 4 に変えていた」ことに気づけます。人数を 4 に直してステータスだけ確定にして保存すると、今度は成功します。

```
DB の推移:  2 / pending  →(A)→  4 / pending  →(B 競合)→  4 / pending  →(B 再保存)→  4 / confirmed
```

### 再保存が成功する理由

競合後の編集画面は DB から最新の行を読み直すので、hidden の `original_updated_at` は 17:04:11 に更新されています。
このとき hidden には `old()` を使わず、必ず DB の値を出しています。
`old()` を使うと古い 17:04:09 が復元され、何度保存しても競合し続けてしまいます。

---

## 実装のポイント

### 確認は PHP の if ではなく、UPDATE の WHERE 句で行う

次のような書き方は **間違い** です。

```php
if ($reservation->updated_at != $original) {   // ① 確認
    throw new ReservationConflictException();
}
$reservation->update($attributes);              // ② 更新
```

①と②の間に他のリクエストの UPDATE が割り込むと、確認をすり抜けてしまいます（Check-Then-Act の競合）。

WHERE 句に条件を入れれば、確認と更新が 1 つの UPDATE 文で行われます。
InnoDB は UPDATE の対象行に排他ロックを取ってから条件を評価するので、割り込む隙間がありません。

```php
$affected = Reservation::query()
    ->whereKey($reservation->getKey())
    ->where('updated_at', $context['original_updated_at'])
    ->update($values);

if ($affected === 0) {
    throw new ReservationConflictException(...);
}
```

### Builder::update() とモデルの save() の違い

| | `$model->save()`（Phase 3） | `Model::query()->...->update()`（Phase 4） |
|---|---|---|
| WHERE 句 | 主キーだけ | 自由に条件を足せる |
| SET 句 | 変更されたカラムだけ | 渡した全カラム |
| casts（型変換） | 効く | **効かない** |
| モデルイベント | 発火する | 発火しない |
| 戻り値 | true / false | 更新件数 |

楽観的ロックには「WHERE 句に条件を足せる」「更新件数が分かる」の 2 点が必要なので、Builder の `update()` を使っています。

casts が効かないので、フォームの生の値（`2026-10-10T19:00`）がそのまま SQL に入ってしまいます。
一度モデルに `fill()` して、DB 形式の値（`2026-10-10 19:00:00`）に変換してから渡しています。

### 更新件数の意味（ATTR_FOUND_ROWS）

MySQL の UPDATE が返す件数は、デフォルトでは **値が実際に変わった行数** です。WHERE 句に一致した行数ではありません。
楽観的ロックは「0 件なら競合」と判定するので、これだと誤検知が起きます（下の「弱点 2」）。

`config/database.php` で `Pdo\Mysql::ATTR_FOUND_ROWS => true` を設定し、**WHERE 句に一致した行数** を返すようにしています。

---

## 弱点

### 弱点 1: 同じ秒の中の更新を見逃す（秒精度の問題）

`updated_at` は TIMESTAMP 型で、**秒単位** でしか記録されません。
B が編集画面を開いたのと同じ秒に A が更新すると、`updated_at` の値が変わらず、B の保存が通ってしまいます。

実際に再現したログです。

```
時刻          操作
17:04:53.145  A が人数を 4 に保存       → updated_at = 17:04:53
17:04:53.227  B が編集画面を開く        → hidden = 17:04:53
17:04:53.325  A が人数を 6 に保存       → updated_at = 17:04:53（同じ秒なので値が変わらない）
17:04:54.946  B がステータスだけ確定にして保存
```

```sql
-- B の UPDATE。A の 2 回目の更新後も updated_at は 17:04:53 のままなので一致してしまう
update ... `number_of_people` = '4', `status` = 'confirmed', ...
 where `reservations`.`id` = 1 and `updated_at` = '2026-10-03 17:04:53'
```

```
DB の推移:  4 → 6（A の 2 回目）→ 4 / confirmed（B が上書き）
```

A が 6 にした変更が消えました。updated_at 方式なのに Lost Update が起きています。

人間が画面を操作する場合はめったに起きません。しかし API やバッチ処理から短い間隔で更新される場合は、現実に起こり得ます。

**対策の例:**

- `timestamps(6)` でマイクロ秒精度にする。見逃す可能性は大きく減るが、ゼロにはならない。
- 更新のたびに必ず増える専用のカラムを使う。これが **Phase 5 の version 方式** です。

### 弱点 2: 誰も更新していないのに競合になる（ATTR_FOUND_ROWS 設定前）

`ATTR_FOUND_ROWS` を設定する前に実際に起きた現象です。

```
17:04:58  updated_at が 17:04:58 になる
17:04:58  A が編集画面を開く（hidden = 17:04:58）
17:04:58  A が何も変えずに保存 → 「他の人が先に更新しました」と表示される
```

```sql
update ... `number_of_people` = '2', ..., `updated_at` = '2026-10-03 17:04:58'
 where `reservations`.`id` = 1 and `updated_at` = '2026-10-03 17:04:58'
```

WHERE 句には一致しています。しかし SET する値がすべて今の値と同じなので、MySQL は「変わった行は 0 件」と返します。
アプリは 0 件を競合とみなすので、誤ってエラーにしていました。

`ATTR_FOUND_ROWS => true` を設定した後は、同じ操作で正常に保存できることを確認済みです。

### 弱点 3: updated_at を他の処理も書き換える

`updated_at` は業務上の「最終更新日時」でもあります。
例えば一括処理で `touch()` された場合、内容は変わっていないのに編集中の全員が競合になります。
逆に、`updated_at` を更新しない処理（`timestamps = false` や生の SQL）で内容が変わると、競合を見逃します。

「排他制御のための値」と「業務上の日時」を 1 つのカラムで兼ねていることが原因です。

---

## 他の方式との違い

| | 排他制御なし（Phase 3） | updated_at（Phase 4） |
|---|---|---|
| 編集画面を開くとき | 何もしない | updated_at を hidden に埋め込む |
| 保存時の WHERE 句 | `id = ?` | `id = ? and updated_at = ?` |
| 競合時 | 上書きされる（気づけない） | 0 件更新になり、エラーを表示 |
| 追加のカラム | 不要 | 不要（既存の updated_at を使う） |
| 弱点 | Lost Update | 同じ秒の更新を見逃す、updated_at の兼用 |

updated_at 方式の利点は、**テーブルにカラムを追加しなくても導入できる** ことです。
既存システムに後から楽観的ロックを入れる場合によく使われます。
