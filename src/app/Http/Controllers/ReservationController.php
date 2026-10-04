<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Exceptions\ReservationConflictException;
use App\Http\Middleware\EnsureOperator;
use App\Http\Requests\ReservationRequest;
use App\Models\Reservation;
use App\Services\EditLockService;
use App\Services\LockModeSetting;
use App\Services\ReservationUpdaters\ReservationUpdaterFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function edit(
        Request $request,
        Reservation $reservation,
        LockModeSetting $lockModeSetting,
        EditLockService $editLock,
    ): View|RedirectResponse {
        $lockMode = $lockModeSetting->current();
        $operator = $request->session()->get(EnsureOperator::SESSION_KEY);

        // -----------------------------------------------------------------
        // 編集ロック方式: 編集画面を開く「この時点」でロックを取る。
        // 取れなければ編集画面を表示せず、誰が編集中かを伝えて詳細画面に戻す。
        //
        // ※ GET リクエストで DB を更新するのは本来避けたい（ブラウザの先読みなどで意図せず実行されうる）。
        //   実務では詳細画面に「編集を開始する」ボタン（POST）を置くことが多い。
        //   ここでは他の方式と同じ操作で比較できるよう、編集画面を開いた時点で取得している。
        // -----------------------------------------------------------------
        if ($lockMode->usesEditLock()
            && ! $editLock->acquire($reservation, $operator, $lockMode->hasLockExpiry())) {
            $reservation->refresh();

            return redirect()
                ->route('reservations.show', $reservation)
                ->with('error', "{$reservation->locked_by} さんが編集中のため、編集できません（{$reservation->locked_at?->format('H:i:s')} から編集中）。");
        }

        return view('reservations.edit', [
            // ロックを取った後の値（locked_until など）を表示するため読み直す
            'reservation' => $reservation->refresh(),
            'statuses' => ReservationStatus::cases(),
            // 編集画面を開いた時点の方式。hidden で送り返し、更新時に変わっていないか確認する
            'lockMode' => $lockMode,
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

        // 編集画面を開いた時点の値（hidden）。どの値を使うかは方式ごとの Updater が決める。
        $context = $request->only([
            'original_updated_at',
            'original_version',
            // 悲観的ロックの検証用オプション
            'hold_seconds',
            'verify_version',
        ]);
        // 編集ロック方式で「誰が保存しようとしているか」。
        // フォームの入力ではなくセッションから取ることで、他人の名前を送ってロックをすり抜けることを防ぐ。
        $context['operator'] = $request->session()->get(EnsureOperator::SESSION_KEY);

        try {
            $updaterFactory->make($mode)->update($reservation, $request->validated(), $context);
        } catch (ReservationConflictException $e) {
            // 編集ロック方式でロックを失った場合は、詳細画面に戻す。
            // 編集画面に戻すと、そこでロックの取得を試みて（他の人が持っていれば）さらに詳細画面へ転送され、
            // 「ロックが失われた」というメッセージが「○○さんが編集中」で上書きされてしまうため。
            if ($mode->usesEditLock()) {
                return redirect()
                    ->route('reservations.show', $reservation)
                    ->with('error', $e->getMessage());
            }

            // 競合を検知した場合は、入力内容を残したまま編集画面に戻す。
            // conflict フラグを渡し、編集画面で「最新の内容」と「あなたの入力」を見比べられるようにする。
            return redirect()
                ->route('reservations.edit', $reservation)
                ->withInput()
                ->with('error', $e->getMessage())
                ->with('conflict', true);
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
