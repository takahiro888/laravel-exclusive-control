<?php

namespace App\Http\Controllers;

use App\Exceptions\ReservationConflictException;
use App\Models\Reservation;
use App\Services\CancelReservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 予約のキャンセル（詳細画面の「キャンセルする」ボタン）
 */
class ReservationCancelController extends Controller
{
    public function __invoke(Request $request, Reservation $reservation, CancelReservation $cancel): RedirectResponse
    {
        $validated = $request->validate([
            'original_version' => ['required', 'integer', 'min:1'],
        ], [
            'original_version.*' => '画面を開いた時点のバージョンが不正です。画面を開き直してください。',
        ]);

        try {
            $cancel($reservation, (int) $validated['original_version']);
        } catch (ReservationConflictException $e) {
            return redirect()->route('reservations.show', $reservation)->with('error', $e->getMessage());
        }

        return redirect()->route('reservations.show', $reservation)->with('success', '予約をキャンセルしました。');
    }
}
