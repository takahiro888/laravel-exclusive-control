<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureOperator;
use App\Models\Reservation;
use App\Services\EditLockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 編集ロックの解放（キャンセル）と強制解除
 */
class EditLockController extends Controller
{
    /**
     * 編集をキャンセルして自分のロックを外す。
     *
     * 「キャンセル」をただのリンクにすると、ロックが残ったままになる。
     * ロックを外すには DB を更新する必要があるので、POST（DELETE）で送る。
     * ※ ブラウザを閉じる・別のページに移動するなど、このボタンを押さずに離れた場合は外れない。
     *   それが「有効期限なし」の編集ロックの弱点。
     */
    public function release(Request $request, Reservation $reservation, EditLockService $editLock): RedirectResponse
    {
        $editLock->release($reservation, $request->session()->get(EnsureOperator::SESSION_KEY));

        return redirect()
            ->route('reservations.show', $reservation)
            ->with('success', '編集をキャンセルし、編集ロックを解放しました。');
    }

    /**
     * 誰のロックでも強制的に外す（放置されたロックの救済用）。
     */
    public function forceRelease(Reservation $reservation, EditLockService $editLock): RedirectResponse
    {
        $holder = $reservation->locked_by;
        $editLock->forceRelease($reservation);

        return redirect()
            ->route('reservations.show', $reservation)
            ->with('success', $holder ? "{$holder} さんの編集ロックを強制解除しました。" : '編集ロックはかかっていませんでした。');
    }
}
