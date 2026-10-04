<?php

namespace App\Services\Batch;

use App\Enums\BatchStrategy;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * 予約ステータスの一括変更バッチ（例: 仮予約の一括確定、期限切れ仮予約の自動キャンセル）
 *
 * どのバッチも「① 対象を読み込む → ② 1件ずつ書き込む」という2段階になる。
 * ①と②の間に、画面の操作や別のバッチが同じ予約を変更すると、
 * ②は「①で読んだ時点の古い前提」で書き込むことになる。これが「バッチの後出し」。
 *
 * 書き方（BatchStrategy）ごとに、②で何を確認するかが違う。
 *
 * | 書き方                | ②の SQL                                                              |
 * |----------------------|----------------------------------------------------------------------|
 * | None                 | update ... set status = ? where id = ?                               |
 * | StateGuard           | update ... set status = ? where id = ? and status = '変更前'           |
 * | StateGuardWithVersion| update ... set status = ?, version = version + 1 where id = ? and status = '変更前' |
 * | Optimistic           | update ... set status = ?, version = version + 1 where id = ? and version = ? |
 * | Pessimistic          | begin; select ... for update; (PHP で状態を確認); update ...; commit  |
 */
class ReservationStatusBatch
{
    /**
     * @param  list<int>|null  $ids  対象を絞る場合の予約ID（検証で特定の予約だけを処理するため）
     * @param  Closure|null  $afterRead  ①と②の間に呼ばれる。検証で「この間に他の処理が割り込む」状況を作るためのフック
     */
    public function run(
        ReservationStatus $from,
        ReservationStatus $to,
        BatchStrategy $strategy,
        ?array $ids = null,
        ?Closure $afterRead = null,
    ): BatchResult {
        // ① 対象を読み込む（どの書き方でも、ここではロックを取らない）
        $targets = Reservation::query()
            ->where('status', $from)
            ->when($ids !== null, fn ($query) => $query->whereKey($ids))
            ->orderBy('id')
            ->get();

        if ($afterRead !== null) {
            $afterRead($targets);
        }

        // ② 1件ずつ書き込む
        $result = new BatchResult();
        foreach ($targets as $reservation) {
            match ($strategy) {
                BatchStrategy::None => $this->writeWithoutCheck($reservation, $to, $result),
                BatchStrategy::StateGuard => $this->writeWithStateGuard($reservation, $from, $to, $result, bumpVersion: false),
                BatchStrategy::StateGuardWithVersion => $this->writeWithStateGuard($reservation, $from, $to, $result, bumpVersion: true),
                BatchStrategy::Optimistic => $this->writeWithVersion($reservation, $to, $result),
                BatchStrategy::Pessimistic => $this->writeWithRowLock($reservation, $from, $to, $result),
            };
        }

        return $result;
    }

    /**
     * なし: ①で読んだモデルをそのまま保存する。
     *
     *   update reservations set status = 'confirmed', updated_at = ? where id = 1
     *
     * ①の後に誰かがキャンセルしていても、WHERE が id だけなので確定に上書きする。
     * また version を上げないので、画面を開いている利用者はこの変更に気づけない。
     */
    private function writeWithoutCheck(Reservation $reservation, ReservationStatus $to, BatchResult $result): void
    {
        $reservation->status = $to;
        $reservation->save();
        $result->markUpdated($reservation->id);
    }

    /**
     * 状態ガード: 「まだ変更前の状態なら」という条件を UPDATE に入れる。
     *
     *   update reservations set status = 'confirmed' [, version = version + 1], updated_at = ?
     *    where id = 1 and status = 'pending'
     *
     * ①の後に誰かがキャンセルしていれば status が変わっているので 0 件になり、上書きしない。
     * bumpVersion = false だと version を上げないので、画面を開いている利用者は気づけない。
     */
    private function writeWithStateGuard(Reservation $reservation, ReservationStatus $from, ReservationStatus $to, BatchResult $result, bool $bumpVersion): void
    {
        $values = ['status' => $to];
        if ($bumpVersion) {
            $values['version'] = DB::raw('version + 1');
        }

        $affected = Reservation::query()
            ->whereKey($reservation->id)
            ->where('status', $from)
            ->update($values);

        $affected === 1
            ? $result->markUpdated($reservation->id)
            : $result->markSkipped($reservation->id, "読み込んだ後に、状態が「{$from->label()}」から変わっていた");
    }

    /**
     * 楽観的ロック: ①で読んだ時点の version を条件にする（画面の version 方式と同じ）。
     *
     *   update reservations set status = 'confirmed', version = version + 1, updated_at = ?
     *    where id = 1 and version = 3
     *
     * 状態ガードより厳しい。status 以外（顧客名など）が変わっただけでも 0 件になり、スキップする。
     */
    private function writeWithVersion(Reservation $reservation, ReservationStatus $to, BatchResult $result): void
    {
        $affected = Reservation::query()
            ->whereKey($reservation->id)
            ->where('version', $reservation->version)
            ->update(['status' => $to, 'version' => DB::raw('version + 1')]);

        $affected === 1
            ? $result->markUpdated($reservation->id)
            : $result->markSkipped($reservation->id, '読み込んだ後に、他の処理で更新されていた（version が違う）');
    }

    /**
     * 悲観的ロック: 1件ずつトランザクションを張り、行ロックを取ってから最新の状態で判定する。
     *
     *   begin
     *   select * from reservations where id = 1 limit 1 for update
     *   update reservations set status = 'confirmed', version = 4, updated_at = ? where id = 1
     *   commit
     *
     * トランザクションを1件ごとに短く区切っているのがポイント。
     * 全件を1つのトランザクションで FOR UPDATE すると、バッチが終わるまで画面の操作が全部待たされる。
     */
    private function writeWithRowLock(Reservation $reservation, ReservationStatus $from, ReservationStatus $to, BatchResult $result): void
    {
        DB::transaction(function () use ($reservation, $from, $to, $result) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->first();

            // ロックを持っているので、PHP で確認してから更新しても割り込まれない
            if ($locked === null || $locked->status !== $from) {
                $result->markSkipped($reservation->id, "ロックを取った時点で、状態が「{$from->label()}」ではなかった");

                return;
            }

            $locked->status = $to;
            $locked->version = $locked->version + 1;
            $locked->save();
            $result->markUpdated($reservation->id);
        });
    }
}
