<?php

namespace App\Services\ReservationUpdaters;

use App\Exceptions\ReservationConflictException;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * 楽観的ロック（version 方式）
 *
 * 考え方:
 *   updated_at 方式と同じく、保存の瞬間に「編集画面を開いたときから行が変わっていないか」を確認する。
 *   違いは目印として「更新のたびに必ず +1 される専用の番号（version）」を使うこと。
 *   時刻ではないので、同じ秒に何回更新されても必ず値が変わる。
 *
 * 排他制御のタイミング:
 *   保存リクエストの UPDATE 文の1回だけ（updated_at 方式と同じ）。
 *
 * 発行される SQL:
 *   update reservations
 *      set number_of_people = 4, version = version + 1, updated_at = '...'
 *    where id = 1
 *      and version = 3          ← 編集画面を開いた時点の version
 *
 * 競合した場合:
 *   他の人が先に更新していれば version が 4 以上になっているので、0 件更新になる。
 *   0 件なら ReservationConflictException を投げる。
 *
 * updated_at 方式との違い:
 *   - 同じ秒の更新も見逃さない（秒精度の問題が無い）
 *   - 排他制御専用のカラムなので、touch() など業務と関係ない処理で値が変わらない
 *   - その代わりカラムを追加する必要があり、すべての更新処理で version を +1 しなければならない
 */
class VersionLockUpdater implements ReservationUpdater
{
    public function update(Reservation $reservation, array $attributes, array $context = []): Reservation
    {
        // hidden で送られてくる値なので、改ざんや欠落に備えて形式を確認する
        $context = Validator::make($context, [
            'original_version' => ['required', 'integer', 'min:1'],
        ], [
            'original_version.*' => '編集開始時のバージョンが不正です。編集画面を開き直してください。',
        ])->validate();

        // Builder::update() は casts を通らないので、DB に保存する形式に変換しておく（Phase 4 と同じ）
        $values = (new Reservation())->fill($attributes)->getAttributes();

        $affected = Reservation::query()
            ->whereKey($reservation->getKey())
            // 確認と更新を1つの UPDATE 文で行う（Check-Then-Act の競合を防ぐ。Phase 4 と同じ理由）
            ->where('version', (int) $context['original_version'])
            ->update([
                ...$values,
                // なぜ PHP で計算した値（$original + 1）ではなく SQL の式にするのか:
                //   WHERE で version = 3 を確認しているので、結果はどちらも 4 になる。
                //   ただ「DB 上の今の値に +1 する」と書いておくと、意図が SQL を見ただけで分かり、
                //   WHERE 条件を書き忘れた場合でも番号が巻き戻ることがない。
                'version' => DB::raw('version + 1'),
            ]);

        // ATTR_FOUND_ROWS を有効にしているので、0 件 = WHERE に一致しなかった = 競合
        // （version は必ず +1 されるので、この設定が無くても誤検知は起きない）
        if ($affected === 0) {
            throw new ReservationConflictException(
                'この予約は、あなたが編集画面を開いた後に他の人が更新しました。最新の内容を確認してから、もう一度保存してください。'
            );
        }

        return $reservation->refresh();
    }
}
