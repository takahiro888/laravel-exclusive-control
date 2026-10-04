<?php

namespace App\Services\Batch;

/**
 * バッチの実行結果。更新できた予約と、スキップした予約（理由付き）を記録する。
 *
 * スキップは「失敗」ではない。読み込んだ後に他の処理が先に変更していたので、
 * 古い前提のまま書き込まずに見送った、という正しい動作。次回の実行で改めて判定される。
 */
class BatchResult
{
    /** @var list<int> */
    public array $updated = [];

    /** @var array<int, string> 予約ID => スキップした理由 */
    public array $skipped = [];

    public function markUpdated(int $id): void
    {
        $this->updated[] = $id;
    }

    public function markSkipped(int $id, string $reason): void
    {
        $this->skipped[$id] = $reason;
    }
}
