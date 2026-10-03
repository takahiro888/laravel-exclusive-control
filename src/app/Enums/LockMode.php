<?php

namespace App\Enums;

/**
 * 排他制御方式
 *
 * 画面上の切り替え UI と、更新処理クラスの選択の両方でこの Enum を使う。
 * 新しい方式を実装したら implemented() を true にし、
 * ReservationUpdaterFactory に対応するクラスを追加する。
 */
enum LockMode: string
{
    case None = 'none';
    case UpdatedAt = 'updated_at';
    case Version = 'version';
    case Pessimistic = 'pessimistic';
    case EditLock = 'edit_lock';
    case EditLockWithExpiry = 'edit_lock_expiry';

    public function label(): string
    {
        return match ($this) {
            self::None => '排他制御なし',
            self::UpdatedAt => '楽観的ロック（updated_at）',
            self::Version => '楽観的ロック（version）',
            self::Pessimistic => '悲観的ロック（SELECT FOR UPDATE）',
            self::EditLock => '編集ロック',
            self::EditLockWithExpiry => '有効期限付き編集ロック',
        };
    }

    /**
     * 画面に出す一言説明。どのタイミングで何を確認する方式なのかを書く。
     */
    public function description(): string
    {
        return match ($this) {
            self::None => '更新時に何も確認しない。後から保存した人の内容で上書きされる（Lost Update が起きる）。',
            self::UpdatedAt => '更新時に「編集開始時の updated_at」と一致するか確認する。',
            self::Version => '更新時に「編集開始時の version」と一致するか確認し、更新のたびに version を +1 する。',
            self::Pessimistic => '更新トランザクションの中で行ロックを取り、他のトランザクションを待たせる。',
            self::EditLock => '編集画面を開いた時点でロックを取り、他の人は編集画面に入れない。',
            self::EditLockWithExpiry => '編集ロックに有効期限を付け、放置されたロックが自動で解放されるようにする。',
        };
    }

    /**
     * どの Phase で実装するか（未実装の方式を画面で案内するため）
     */
    public function phase(): int
    {
        return match ($this) {
            self::None => 3,
            self::UpdatedAt => 4,
            self::Version => 5,
            self::Pessimistic => 6,
            self::EditLock, self::EditLockWithExpiry => 7,
        };
    }

    public function implemented(): bool
    {
        return match ($this) {
            self::None, self::UpdatedAt => true,
            default => false,
        };
    }
}
