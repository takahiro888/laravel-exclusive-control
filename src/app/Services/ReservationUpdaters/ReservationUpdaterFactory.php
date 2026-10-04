<?php

namespace App\Services\ReservationUpdaters;

use App\Enums\LockMode;

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
            LockMode::Pessimistic => new PessimisticLockUpdater(),
            // 保存時の処理は有効期限の有無で変わらない（期限が効くのはロック取得時）
            LockMode::EditLock, LockMode::EditLockWithExpiry => new EditLockUpdater(),
        };
    }
}
