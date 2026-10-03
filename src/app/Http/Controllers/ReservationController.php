<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Requests\ReservationRequest;
use App\Models\Reservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ReservationController extends Controller
{
    public function index(): View
    {
        $reservations = Reservation::query()
            ->orderBy('id')
            ->paginate(20);

        return view('reservations.index', compact('reservations'));
    }

    public function show(Reservation $reservation): View
    {
        return view('reservations.show', compact('reservation'));
    }

    public function create(): View
    {
        return view('reservations.create', [
            'reservation' => new Reservation(['status' => ReservationStatus::Pending]),
            'statuses' => ReservationStatus::cases(),
        ]);
    }

    public function store(ReservationRequest $request): RedirectResponse
    {
        $reservation = Reservation::create($request->validated());

        return redirect()
            ->route('reservations.show', $reservation)
            ->with('success', '予約を登録しました。');
    }

    public function edit(Reservation $reservation): View
    {
        return view('reservations.edit', [
            'reservation' => $reservation,
            'statuses' => ReservationStatus::cases(),
        ]);
    }

    /**
     * 予約の更新（排他制御なし）
     *
     * ここが Phase 3 以降の比較の基準になる「何もしていない」更新処理。
     *
     * 処理の流れと発行される SQL のイメージ:
     *   1. ルートモデルバインディングで最新の行を取得
     *        select * from reservations where id = 1 limit 1
     *   2. フォームの値で上書きして保存（Eloquent は変更されたカラムだけを UPDATE する）
     *        update reservations set number_of_people = 4, updated_at = '...' where id = 1
     *
     * WHERE 句が id だけなので、「編集画面を開いた後に他の人が更新したかどうか」は一切確認しない。
     * フォームには編集画面を開いた時点の値がすべて入っているため、
     * 後から保存した人の古い値で、先に保存した人の変更が上書きされる（Lost Update）。
     */
    public function update(ReservationRequest $request, Reservation $reservation): RedirectResponse
    {
        $reservation->update($request->validated());

        return redirect()
            ->route('reservations.show', $reservation)
            ->with('success', '予約を更新しました。');
    }

    public function destroy(Reservation $reservation): RedirectResponse
    {
        $reservation->delete();

        return redirect()
            ->route('reservations.index')
            ->with('success', '予約を削除しました。');
    }
}
