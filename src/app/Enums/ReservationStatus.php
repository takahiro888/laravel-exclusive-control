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

    /**
     * この状態から $to へ変更してよいか（状態遷移のルール）
     *
     *   仮予約 ──→ 確定
     *     │         │
     *     └──→ キャンセル ←┘      キャンセルは終端（どこにも戻れない）
     *
     * 排他制御が「誰の変更を優先するか」を決めるのに対して、状態遷移のルールは
     * 「どんな順番で処理されても、ありえない状態にならない」ことを保証する。
     * 例えば「キャンセル済み → 確定」は、どの処理がどの順番で実行されても起きてはいけない。
     */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Cancelled],
            self::Cancelled => [],
        }, true);
    }

    /**
     * $to へ変更できる状態の一覧（UPDATE の WHERE status IN (...) に使う）
     *
     * @return list<self>
     */
    public static function canTransitionFrom(self $to): array
    {
        return array_values(array_filter(self::cases(), fn (self $from) => $from->canTransitionTo($to)));
    }
}
