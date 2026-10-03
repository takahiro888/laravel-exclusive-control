<?php

namespace App\Services;

use App\Enums\LockMode;
use Illuminate\Support\Facades\Cache;

/**
 * 現在の排他制御方式を保存・取得する。
 *
 * なぜセッションではなくキャッシュに保存するのか:
 *   セッションはブラウザごとに別々なので、ブラウザ A は「なし」、ブラウザ B は「version」
 *   のように方式がずれてしまう。排他制御は「全員が同じルールに従う」ことで初めて意味があるため、
 *   アプリ全体で1つの値を共有する。
 *
 * CACHE_STORE=database なので、実体は MySQL の cache テーブルに保存される。
 * コンテナを再起動しても選択は残る。
 */
class LockModeSetting
{
    private const CACHE_KEY = 'lock_mode';

    public function current(): LockMode
    {
        $value = Cache::get(self::CACHE_KEY);

        // 未設定、または未実装の方式が残っていた場合は「排他制御なし」として扱う
        $mode = LockMode::tryFrom((string) $value) ?? LockMode::None;

        return $mode->implemented() ? $mode : LockMode::None;
    }

    public function change(LockMode $mode): void
    {
        Cache::forever(self::CACHE_KEY, $mode->value);
    }
}
