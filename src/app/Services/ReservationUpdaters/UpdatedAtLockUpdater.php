<?php

namespace App\Services\ReservationUpdaters;

use App\Exceptions\ReservationConflictException;
use App\Models\Reservation;
use Illuminate\Support\Facades\Validator;

/**
 * 楽観的ロック（updated_at 方式）
 *
 * 考え方:
 *   「競合はめったに起きない」と楽観的に考え、編集中はロックを取らない。
 *   その代わり保存の瞬間に「編集画面を開いたときから行が変わっていないか」を確認する。
 *   updated_at は行が更新されるたびに変わるので、これを「行のバージョン」として使う。
 *
 * 排他制御のタイミング:
 *   保存リクエストの UPDATE 文の1回だけ。編集画面を開いたときは何もしない。
 *
 * 発行される SQL:
 *   update reservations
 *      set number_of_people = 4, updated_at = '2026-10-03 17:00:05'
 *    where id = 1
 *      and updated_at = '2026-10-03 17:00:00'   ← 編集画面を開いた時点の updated_at
 *
 * 競合した場合:
 *   他の人が先に更新していれば updated_at が変わっているので、WHERE 句に一致する行が無く 0 件更新になる。
 *   0 件なら ReservationConflictException を投げ、利用者に「他の人が先に更新した」と伝える。
 *
 * 弱点:
 *   updated_at は TIMESTAMP 型で秒精度。同じ秒の中で2回更新されると値が変わらないため、
 *   「開いた後の更新」がその秒の中で起きると競合を見逃す（docs/phase4-updated-at.md で再現）。
 */
class UpdatedAtLockUpdater implements ReservationUpdater
{
    public function update(Reservation $reservation, array $attributes, array $context = []): Reservation
    {
        // hidden で送られてくる値なので、改ざんや欠落に備えて形式を確認する
        $context = Validator::make($context, [
            'original_updated_at' => ['required', 'date_format:Y-m-d H:i:s'],
        ], [
            'original_updated_at.*' => '編集開始時の更新日時が不正です。編集画面を開き直してください。',
        ])->validate();

        // -----------------------------------------------------------------
        // なぜ PHP の if で比較せず、UPDATE の WHERE 句で比較するのか
        //
        // 悪い例:
        //   if ($reservation->updated_at != $original) { 競合 }   ← ① SELECT 済みの値で確認
        //   $reservation->update($attributes);                      ← ② UPDATE
        //
        // ①と②の間に他のリクエストの UPDATE が割り込むと、確認をすり抜けて上書きしてしまう
        // （Check-Then-Act の競合）。
        // WHERE 句に条件を入れれば「確認」と「更新」が1つの UPDATE 文で行われる。
        // InnoDB は UPDATE 対象の行に排他ロックを取ってから条件を評価するので、割り込む隙間が無い。
        // -----------------------------------------------------------------
        // Builder::update() はモデルの casts を通らないため、フォームの生の値
        // （予約日時の "2026-10-10T19:00" など）がそのまま SQL に入ってしまう。
        // 一度モデルに fill して、DB に保存する形式（"2026-10-10 19:00:00"）に変換した値を使う。
        $values = (new Reservation())->fill($attributes)->getAttributes();

        $affected = Reservation::query()
            ->whereKey($reservation->getKey())
            ->where('updated_at', $context['original_updated_at'])
            // Eloquent の Builder::update() は updated_at = 現在時刻 を自動で SET に加える。
            // ※ モデルの save() と違い、変更されたカラムだけでなく渡した全カラムを SET する。
            //   また Eloquent のモデルイベント（saving / updated 等）は発火しない。
            ->update($values);

        // MySQL が返す件数は、デフォルトでは「WHERE に一致した件数」ではなく「実際に値が変わった件数」。
        // config/database.php で ATTR_FOUND_ROWS を有効にし、「一致した件数」が返るようにしている。
        // これにより「0件 = WHERE に一致しなかった = 他の人が先に更新した」と判定できる。
        if ($affected === 0) {
            throw new ReservationConflictException(
                'この予約は、あなたが編集画面を開いた後に他の人が更新しました。最新の内容を確認してから、もう一度保存してください。'
            );
        }

        return $reservation->refresh();
    }
}
