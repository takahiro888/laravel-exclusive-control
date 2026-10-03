<?php

namespace App\Enums;

/**
 * 予約ステータス
 *
 * DB には value（英字）を保存し、画面には label()（日本語）を表示する。
 * Backed Enum にしておくと、モデルの casts で自動的に Enum に変換され、
 * バリデーションでも Rule::enum() で「定義済みの値か」をチェックできる。
 */
enum ReservationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '仮予約',
            self::Confirmed => '確定',
            self::Cancelled => 'キャンセル',
        };
    }
}
