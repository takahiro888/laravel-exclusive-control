<?php

namespace App\Providers;

use App\Enums\LockMode;
use App\Services\LockModeSetting;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 共通レイアウトのヘッダーに「現在の排他制御方式」を常に表示するため、
        // layouts.app が描画されるたびに現在の方式と方式一覧を渡す（View Composer）。
        // 各コントローラで毎回 view に渡す必要がなくなる。
        View::composer('layouts.app', function ($view) {
            $view->with([
                'currentLockMode' => app(LockModeSetting::class)->current(),
                'lockModes' => LockMode::cases(),
            ]);
        });
    }
}
