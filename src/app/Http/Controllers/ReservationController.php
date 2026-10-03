<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Exceptions\ReservationConflictException;
use App\Http\Requests\ReservationRequest;
use App\Models\Reservation;
use App\Services\LockModeSetting;
use App\Services\ReservationUpdaters\ReservationUpdaterFactory;
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

    public function edit(Reservation $reservation, LockModeSetting $lockModeSetting): View
    {
        return view('reservations.edit', [
            'reservation' => $reservation,
            'statuses' => ReservationStatus::cases(),
            // 編集画面を開いた時点の方式。hidden で送り返し、更新時に変わっていないか確認する
            'lockMode' => $lockModeSetting->current(),
        ]);
    }

    /**
     * 予約の更新
     *
     * 実際の更新処理は、選択中の排他制御方式に対応する ReservationUpdater に任せる。
     * コントローラの役割は「方式を選ぶ」「競合したら画面にメッセージを返す」ことだけにして、
     * 方式ごとの違いを Updater クラスに閉じ込めている。
     */
    public function update(
        ReservationRequest $request,
        Reservation $reservation,
        LockModeSetting $lockModeSetting,
        ReservationUpdaterFactory $updaterFactory,
    ): RedirectResponse {
        $mode = $lockModeSetting->current();

        // 編集画面を開いた後に方式が切り替えられていたら、更新させない。
        // 例えば「なし」で開いたフォームには version の hidden が無いので、
        // そのまま version 方式で処理すると正しく判定できないため。
        if ($request->input('lock_mode') !== $mode->value) {
            return redirect()
                ->route('reservations.edit', $reservation)
                ->withInput()
                ->with('error', "編集中に排他制御方式が「{$mode->label()}」に変更されました。内容を確認して、もう一度保存してください。");
        }

        try {
            $updaterFactory->make($mode)->update($reservation, $request->validated());
        } catch (ReservationConflictException $e) {
            // Phase 4 以降: 競合を検知した場合は、入力内容を残したまま編集画面に戻す
            return redirect()
                ->route('reservations.edit', $reservation)
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('reservations.show', $reservation)
            ->with('success', "予約を更新しました（{$mode->label()}）。");
    }

    public function destroy(Reservation $reservation): RedirectResponse
    {
        $reservation->delete();

        return redirect()
            ->route('reservations.index')
            ->with('success', '予約を削除しました。');
    }
}
