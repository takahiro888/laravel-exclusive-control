<?php

use App\Http\Controllers\EditLockController;
use App\Http\Controllers\LockModeController;
use App\Http\Controllers\OperatorController;
use App\Http\Controllers\ReservationCancelController;
use App\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;

// トップページは予約一覧に転送する
Route::redirect('/', '/reservations');

// index / create / store / show / edit / update / destroy の7ルートをまとめて定義する
Route::resource('reservations', ReservationController::class);

// 排他制御方式の切り替え（アプリ全体で1つの設定を書き換える）
Route::put('lock-mode', [LockModeController::class, 'update'])->name('lock-mode.update');

// 操作者名の変更（セッションに保存）
Route::put('operator', [OperatorController::class, 'update'])->name('operator.update');

// 編集ロックの解放（キャンセル）と強制解除
Route::delete('reservations/{reservation}/edit-lock', [EditLockController::class, 'release'])->name('reservations.edit-lock.release');
Route::delete('reservations/{reservation}/edit-lock/force', [EditLockController::class, 'forceRelease'])->name('reservations.edit-lock.force');

// 予約のキャンセル（常に version による楽観的ロック。Phase 10 の組み合わせ検証用）
Route::post('reservations/{reservation}/cancel', ReservationCancelController::class)->name('reservations.cancel');
