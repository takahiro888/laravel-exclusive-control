<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Exceptions\ReservationConflictException;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * 予約のキャンセル（利用者の操作）
 *
 * 画面の「排他制御方式」の切り替えとは独立して、常に version による楽観的ロックで処理する。
 * Phase 10 で「楽観的ロックのキャンセル処理」と「バッチ」の組み合わせを検証するための処理。
 *
 * 発行される SQL:
 *   update reservations
 *      set status = 'cancelled', version = version + 1, updated_at = '...'
 *    where id = 1
 *      and version = 3                              ← 画面を開いた時点の version（楽観的ロック）
 *      and status in ('pending', 'confirmed')       ← キャンセルできる状態か（状態ガード）
 *
 * version の条件だけでも「開いた後の変更」は検知できる。状態ガードは、
 * 「キャンセル済みの予約をもう一度キャンセルする」のような遷移ルール違反を、SQL のレベルで防ぐための保険。
 */
class CancelReservation
{
    public function __invoke(Reservation $reservation, int $originalVersion): void
    {
        $to = ReservationStatus::Cancelled;

        $affected = Reservation::query()
            ->whereKey($reservation->getKey())
            ->where('version', $originalVersion)
            ->whereIn('status', ReservationStatus::canTransitionFrom($to))
            ->update([
                'status' => $to,
                'version' => DB::raw('version + 1'),
            ]);

        if ($affected === 1) {
            return;
        }

        // 0件: 何が変わっていたのかを読み直して、利用者に分かるメッセージにする
        $current = $reservation->refresh();

        throw new ReservationConflictException(match (true) {
            $current->status === $to => 'この予約はすでにキャンセルされています。',
            default => "この予約は、画面を開いた後に他の人またはバッチ処理で更新されました（現在のステータス: {$current->status->label()}）。最新の状態を確認してから、もう一度操作してください。",
        });
    }
}
