<?php

namespace App\Services\ReservationUpdaters;

use App\Models\Reservation;

/**
 * 排他制御なし
 *
 * 排他制御のタイミング: なし
 *
 * 発行される SQL:
 *   select * from reservations where id = 1 limit 1      ← ルートモデルバインディング（コントローラに来る前）
 *   update reservations set number_of_people = 4, updated_at = '...' where id = 1
 *
 * 競合した場合:
 *   検知できない。UPDATE の WHERE 句が id だけなので、他の人が先に更新していても必ず成功する。
 *   結果として、後から保存した人の内容で先の更新が上書きされる（Lost Update）。
 *
 * Phase 4 以降の方式は、この UPDATE の WHERE 句に条件を足したり、
 * UPDATE の前にロックを取ったりすることで Lost Update を防ぐ。
 */
class NoLockUpdater implements ReservationUpdater
{
    public function update(Reservation $reservation, array $attributes, array $context = []): Reservation
    {
        // Eloquent の update() は fill() + save()。
        // save() は「読み込んだ時点の値から変わったカラム」だけを UPDATE する。
        // ただしフォームは編集開始時点の全項目を送ってくるため、
        // 他の人が変更したカラムも「古い値への変更」として UPDATE に含まれてしまう。
        $reservation->update($attributes);

        return $reservation;
    }
}
