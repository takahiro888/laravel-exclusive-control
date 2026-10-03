<?php

use App\Http\Controllers\LockModeController;
use App\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;

// トップページは予約一覧に転送する
Route::redirect('/', '/reservations');

// index / create / store / show / edit / update / destroy の7ルートをまとめて定義する
Route::resource('reservations', ReservationController::class);

// 排他制御方式の切り替え（アプリ全体で1つの設定を書き換える）
Route::put('lock-mode', [LockModeController::class, 'update'])->name('lock-mode.update');
