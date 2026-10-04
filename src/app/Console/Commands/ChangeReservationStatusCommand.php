<?php

namespace App\Console\Commands;

use App\Enums\BatchStrategy;
use App\Enums\ReservationStatus;
use App\Services\Batch\ReservationStatusBatch;
use Illuminate\Console\Command;

/**
 * 予約ステータスの一括変更バッチ（Phase 10 の検証用）
 *
 * 例:
 *   # 仮予約を一括確定する（排他制御なし）。読み込んだ後に 15 秒待つ
 *   php artisan reservations:change-status pending confirmed --strategy=none --pause=15
 *
 *   # 仮予約を自動キャンセルする（推奨の書き方）。予約 1 だけを対象にする
 *   php artisan reservations:change-status pending cancelled --strategy=state_guard_version --id=1
 */
class ChangeReservationStatusCommand extends Command
{
    protected $signature = 'reservations:change-status
        {from : 変更前のステータス（pending / confirmed / cancelled）}
        {to : 変更後のステータス}
        {--strategy=state_guard_version : 排他制御の書き方（none / state_guard / state_guard_version / optimistic / pessimistic）}
        {--id=* : 対象の予約ID（省略時は変更前のステータスの予約すべて）}
        {--pause=0 : 対象を読み込んだ後、書き込む前に待つ秒数（この間に画面で操作して「後出し」を再現する）}';

    protected $description = '予約ステータスを一括で変更する（排他制御の書き方を選べる検証用バッチ）';

    public function handle(ReservationStatusBatch $batch): int
    {
        $from = ReservationStatus::tryFrom($this->argument('from'));
        $to = ReservationStatus::tryFrom($this->argument('to'));
        $strategy = BatchStrategy::tryFrom($this->option('strategy'));

        if ($from === null || $to === null || $strategy === null) {
            $this->error('ステータスまたは --strategy の値が不正です。');

            return self::INVALID;
        }

        // バッチ自身も状態遷移のルールに従う（キャンセル済み → 確定 のようなバッチは作らせない）
        if (! $from->canTransitionTo($to)) {
            $this->error("「{$from->label()}」から「{$to->label()}」への変更は、状態遷移のルールで許可されていません。");

            return self::INVALID;
        }

        $ids = array_map('intval', $this->option('id')) ?: null;
        $pause = (int) $this->option('pause');

        $this->info("書き方: {$strategy->label()}");
        $this->info("「{$from->label()}」→「{$to->label()}」");

        $result = $batch->run($from, $to, $strategy, $ids, function ($targets) use ($pause) {
            $this->line(now()->format('H:i:s').' 対象を読み込みました: ID '.($targets->pluck('id')->implode(', ') ?: 'なし'));
            if ($pause > 0) {
                $this->warn("{$pause} 秒待ちます。この間に画面で同じ予約を操作すると「後出し」を再現できます。");
                sleep($pause);
            }
            $this->line(now()->format('H:i:s').' 書き込みを開始します。');
        });

        $this->table(['ID', '結果', '理由'], [
            ...array_map(fn ($id) => [$id, '更新', ''], $result->updated),
            ...array_map(fn ($id, $reason) => [$id, 'スキップ', $reason], array_keys($result->skipped), $result->skipped),
        ]);

        return self::SUCCESS;
    }
}
