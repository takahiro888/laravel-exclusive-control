<?php

namespace Tests\Feature;

use App\Enums\BatchStrategy;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\Batch\ReservationStatusBatch;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithReservations;
use Tests\TestCase;

/**
 * Phase 10: キャンセル処理を悲観的ロックにすれば、排他制御なしのバッチの後出しを防げるか
 *
 * 結論: 防げない。
 * 悲観的ロックは「ロックを持っている間」だけ他の書き込みを待たせる。
 * キャンセルがコミットしてロックを外した後に来るバッチの UPDATE（where id = ?）は、そのまま上書きする。
 * ロックの最中にバッチが来た場合も、待たされた後に上書きする。
 *
 * 別プロセスや別の接続からデータが見える必要があるので DatabaseTruncation を使う（PessimisticLockTest と同じ理由）。
 */
class PessimisticCancelTest extends TestCase
{
    use DatabaseTruncation, InteractsWithReservations;

    protected function tearDown(): void
    {
        DB::table('reservations')->truncate();
        parent::tearDown();
    }

    #[Test]
    public function キャンセルを悲観的ロックにしても_コミット後に来たバッチは上書きする(): void
    {
        $reservation = $this->createReservation();

        app(ReservationStatusBatch::class)->run(
            ReservationStatus::Pending, ReservationStatus::Confirmed, BatchStrategy::None, [$reservation->id],
            afterRead: function () use ($reservation) {
                // 悲観的ロックのキャンセル: 行ロックを取り、状態を確認してからキャンセルしてコミット
                DB::transaction(function () use ($reservation) {
                    $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
                    $this->assertTrue($locked->status->canTransitionTo(ReservationStatus::Cancelled));
                    $locked->status = ReservationStatus::Cancelled;
                    $locked->save();
                });                                         // ← ここでロックが外れる
                $this->assertSame('cancelled', $reservation->refresh()->status->value);
            },
        );

        $this->assertSame('confirmed', $reservation->refresh()->status->value, 'キャンセルが確定で上書きされる');
    }

    #[Test]
    public function キャンセルのロック中にバッチが来ても_待たされた後に上書きする(): void
    {
        $reservation = $this->createReservation();
        $marker = sys_get_temp_dir().'/cancel-locked-'.uniqid();

        $process = null;
        $waited = null;
        app(ReservationStatusBatch::class)->run(
            ReservationStatus::Pending, ReservationStatus::Confirmed, BatchStrategy::None, [$reservation->id],
            afterRead: function () use ($reservation, $marker, &$process, &$waited) {
                // 別プロセスで「悲観的ロックのキャンセル」を動かし、ロックを持ったまま 2 秒待たせる
                $process = $this->startPessimisticCancel($reservation->id, $marker, holdSeconds: 2);
                $deadline = microtime(true) + 5;
                while (! file_exists($marker) && microtime(true) < $deadline) {
                    usleep(20_000);
                }
                $this->assertFileExists($marker, 'キャンセルのプロセスがロックを取得する');
                $waited = microtime(true);
                // この後、バッチが UPDATE を発行する → キャンセルのコミットまで待たされる
            },
        );
        $waited = microtime(true) - $waited;

        $process->wait();
        @unlink($marker);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $this->assertGreaterThan(1.0, $waited, 'バッチの UPDATE はキャンセルのロックで待たされる');
        $this->assertSame('confirmed', $reservation->refresh()->status->value, '待たされた後に、キャンセルを確定で上書きする');
    }

    private function startPessimisticCancel(int $id, string $marker, int $holdSeconds): Process
    {
        $db = config('database.connections.mysql');
        $code = <<<'PHP'
            [$dsn, $user, $password, $id, $marker, $hold] = array_slice($argv, 1);
            $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->beginTransaction();
            $pdo->query("select * from reservations where id = {$id} for update")->fetch();
            $pdo->exec("update reservations set status = 'cancelled', version = version + 1 where id = {$id}");
            touch($marker);
            sleep((int) $hold);
            $pdo->commit();
            PHP;

        $process = new Process([
            PHP_BINARY, '-r', $code, '--',
            "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']}",
            $db['username'], $db['password'], (string) $id, $marker, (string) $holdSeconds,
        ]);
        $process->start();

        return $process;
    }
}
