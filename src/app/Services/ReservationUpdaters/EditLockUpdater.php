<?php

namespace App\Services\ReservationUpdaters;

use App\Exceptions\ReservationConflictException;
use App\Models\Reservation;
use App\Services\EditLockService;

/**
 * 編集ロック方式の保存処理（有効期限なし・ありで共通）
 *
 * 排他制御のタイミング:
 *   ロックの取得は編集画面を開いたとき（EditLockService::acquire）。
 *   保存時は「自分がまだロックを持っているか」を UPDATE の WHERE 句で確認し、同じ UPDATE でロックを外す。
 *
 * 発行される SQL:
 *   update reservations
 *      set number_of_people = 4, ..., locked_by = NULL, locked_at = NULL, locked_until = NULL, updated_at = '...'
 *    where id = 1
 *      and locked_by = 'A'        ← 自分がロックを持っている場合だけ更新する
 *
 * 競合した場合（0件更新）:
 *   通常は編集画面に入れないので、保存時に競合することはない。0件になるのは次のような場合。
 *   - 有効期限切れの後に、他の人がロックを取った（さらに保存してロックを外した場合も含む）
 *   - 他の人がロックを強制解除した
 *
 * なぜ保存時に期限（locked_until）を確認しないのか:
 *   期限が切れていても、誰もロックを取っていなければ locked_by は自分のまま。
 *   その間に他の人は編集していないので、保存を許しても Lost Update は起きない。
 *   他の人がロックを取った時点で locked_by が変わるので、それだけ確認すれば十分。
 */
class EditLockUpdater implements ReservationUpdater
{
    public function update(Reservation $reservation, array $attributes, array $context = []): Reservation
    {
        // 操作者はリクエストの入力ではなく、サーバー側のセッションから渡される（なりすまし防止）
        $operator = (string) ($context['operator'] ?? '');

        $values = (new Reservation())->fill($attributes)->getAttributes();

        $affected = Reservation::query()
            ->whereKey($reservation->getKey())
            ->where('locked_by', $operator)
            // 業務データの更新なので、Eloquent の Builder::update() で updated_at も更新する
            ->update([...$values, ...EditLockService::unlockedColumns()]);

        if ($affected === 0) {
            $current = $reservation->refresh();

            throw new ReservationConflictException($current->locked_by
                ? "編集ロックが失われました。現在は {$current->locked_by} さんが編集中です（有効期限切れや強制解除の後に、編集を開始されました）。"
                : '編集ロックが失われました（有効期限切れの後に他の人が保存した、または強制解除された可能性があります）。最新の内容を確認してください。'
            );
        }

        return $reservation->refresh();
    }
}
