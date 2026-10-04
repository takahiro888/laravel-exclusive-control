<?php

namespace App\Enums;

/**
 * ステータス一括変更バッチの排他制御の書き方（Phase 10 の比較対象）
 */
enum BatchStrategy: string
{
    case None = 'none';
    case StateGuard = 'state_guard';
    case StateGuardWithVersion = 'state_guard_version';
    case Optimistic = 'optimistic';
    case Pessimistic = 'pessimistic';

    public function label(): string
    {
        return match ($this) {
            self::None => 'なし（読み込んでそのまま保存）',
            self::StateGuard => '状態ガード（WHERE status = 変更前）',
            self::StateGuardWithVersion => '状態ガード + version を上げる（推奨）',
            self::Optimistic => '楽観的ロック（WHERE version = 読んだ時点）',
            self::Pessimistic => '悲観的ロック（1件ずつ FOR UPDATE）',
        };
    }
}
