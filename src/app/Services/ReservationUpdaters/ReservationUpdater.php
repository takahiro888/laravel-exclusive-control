<?php

namespace App\Services\ReservationUpdaters;

use App\Exceptions\ReservationConflictException;
use App\Models\Reservation;

/**
 * 予約更新処理の共通インターフェース（Strategy パターン）
 *
 * なぜ方式ごとにクラスを分けるのか:
 *   1つのメソッドに if ($mode === ...) を並べると、方式ごとの違いが読み取りにくくなる。
 *   クラスを分けておけば、NoLockUpdater と VersionLockUpdater を並べて読むだけで
 *   「何が増えたのか」がそのまま方式の違いになる。
 */
interface ReservationUpdater
{
    /**
     * @param  Reservation  $reservation  更新対象（ルートモデルバインディングで取得したもの）
     * @param  array<string, mixed>  $attributes  バリデーション済みの業務データ
     * @param  array<string, mixed>  $context  編集画面を開いた時点の情報。
     *                                          Phase 4 以降で updated_at や version を渡す。
     *
     * @throws ReservationConflictException 競合を検知した場合
     */
    public function update(Reservation $reservation, array $attributes, array $context = []): Reservation;
}
