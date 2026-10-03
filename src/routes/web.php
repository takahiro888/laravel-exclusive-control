<?php

use App\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;

// トップページは予約一覧に転送する
Route::redirect('/', '/reservations');

// index / create / store / show / edit / update / destroy の7ルートをまとめて定義する
Route::resource('reservations', ReservationController::class);
