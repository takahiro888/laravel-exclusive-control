<?php

namespace App\Services\ReservationUpdaters;

use App\Enums\LockMode;
use LogicException;

/**
 * 排他制御方式に対応する更新クラスを返す。
 * 新しい方式を実装したら、ここに1行追加する。
 */
class ReservationUpdaterFactory
{
    public function make(LockMode $mode): ReservationUpdater
    {
        return match ($mode) {
            LockMode::None => new NoLockUpdater(),
            LockMode::UpdatedAt => new UpdatedAtLockUpdater(),
            LockMode::Version => new VersionLockUpdater(),
            default => throw new LogicException("{$mode->label()} は Phase {$mode->phase()} で実装予定です。"),
        };
    }
}
