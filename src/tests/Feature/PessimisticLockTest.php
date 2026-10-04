<?php

namespace Tests\Feature;

use App\Enums\LockMode;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithReservations;
use Tests\TestCase;

/**
 * Phase 6: SELECT ... FOR UPDATE を利用した悲観的ロック
 *
 * なぜ RefreshDatabase ではなく DatabaseTruncation なのか:
 *   RefreshDatabase は各テストをトランザクションで囲み、最後にロールバックする。
 *   するとテストで作った予約はコミットされず、別の DB 接続（他のトランザクション）からは見えない。
 *   このテストでは「別のトランザクションがロックを持っている」状況を作る必要があるので、
 *   データを本当にコミットし、テストの前にテーブルを空にする DatabaseTruncation を使う。
 */
class PessimisticLockTest extends TestCase
{
    use DatabaseTruncation, InteractsWithReservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLockMode(LockMode::Pessimistic);

        // 「他の利用者のトランザクション」を表す2本目の接続（同じ DB への別のセッション）
        config(['database.connections.other' => config('database.connections.mysql')]);
    }

    protected function tearDown(): void
    {
        // ロックを持ったまま終わらないよう、2本目の接続のトランザクションを必ず終わらせる
        if (DB::connection('other')->transactionLevel() > 0) {
            DB::connection('other')->rollBack();
        }
        DB::purge('other');

        // DatabaseTruncation はテストの「前」にテーブルを空にするだけなので、
        // コミットしたデータが残り、後に実行される RefreshDatabase のテストから見えてしまう。
        // 他のテストに影響しないよう、終了時にも空にしておく。
        DB::table('reservations')->truncate();

        parent::tearDown();
    }

    #[Test]
    public function 保存時にトランザクションの中で_SELECT_FOR_UPDATE_を発行する(): void
    {
        $reservation = $this->createReservation();
        $form = $this->openEdit($reservation);

        DB::enableQueryLog();
        $this->save($reservation, $form, ['number_of_people' => 4]);
        $queries = array_column(DB::getQueryLog(), 'query');

        $forUpdate = collect($queries)->search(fn ($sql) => str_ends_with($sql, 'for update'));
        $update = collect($queries)->search(fn ($sql) => str_starts_with($sql, 'update `reservations`'));

        $this->assertNotFalse($forUpdate, 'SELECT ... FOR UPDATE が発行される');
        $this->assertGreaterThan($forUpdate, $update, 'ロックを取ってから UPDATE する');
        $this->assertSame(4, $reservation->refresh()->number_of_people);
        $this->assertSame(2, $reservation->version);
    }

    #[Test]
    public function 開いた後に他の人が更新していたら競合になり上書きしない(): void
    {
        $reservation = $this->createReservation();
        $formA = $this->openEdit($reservation, 'A');
        $formB = $this->openEdit($reservation, 'B');

        $this->save($reservation, $formA, ['number_of_people' => 4], 'A');
        $this->save($reservation, $formB, ['status' => 'confirmed'], 'B')
            ->assertSessionHas('conflict', true);

        $this->assertSame(4, $reservation->refresh()->number_of_people);
    }

    #[Test]
    public function version_の確認を外すと_FOR_UPDATE_だけでは_LostUpdate_を防げない(): void
    {
        $reservation = $this->createReservation();
        $formA = $this->openEdit($reservation, 'A');
        $formB = $this->openEdit($reservation, 'B');

        $this->save($reservation, $formA, ['number_of_people' => 4, 'verify_version' => '0'], 'A');
        $this->save($reservation, $formB, ['status' => 'confirmed', 'verify_version' => '0'], 'B')
            ->assertRedirect(route('reservations.show', $reservation));

        // ロックは保存リクエストの中でしか効かないので、「画面を開いてから」の変更は守れない
        $this->assertSame(2, $reservation->refresh()->number_of_people, 'A の 4名 が消える');
    }

    #[Test]
    public function 他のトランザクションがロック中なら待たされ_解放後の最新の行で判定する(): void
    {
        $reservation = $this->createReservation();
        $formB = $this->openEdit($reservation, 'B');      // version = 1 で開く

        // 別の PHP プロセスで「A の保存処理」を動かす:
        //   ロックを取る → 2秒待つ → 人数を 8 にして version を上げる → コミット
        // テスト本体とは別のプロセスなので、本当に同時に動く。
        $lockedMarker = tempnam(sys_get_temp_dir(), 'locked');
        unlink($lockedMarker);
        $process = $this->startLockHolder($reservation->id, $lockedMarker, holdSeconds: 2);

        $this->waitUntil(fn () => file_exists($lockedMarker), 'A のプロセスがロックを取得する');

        // A がロック中に B が保存する
        $startedAt = microtime(true);
        $response = $this->save($reservation, $formB, ['status' => 'confirmed'], 'B');
        $waited = microtime(true) - $startedAt;

        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $this->assertGreaterThan(1.0, $waited, 'B の保存は A のコミットまで待たされる');
        // 待った後に B が読んだのは「A のコミット後の最新の行」なので、version の違いに気づける
        $response->assertSessionHas('conflict', true);
        $this->assertSame(8, $reservation->refresh()->number_of_people, 'A の変更が守られる');
        $this->assertSame('pending', $reservation->status->value);
    }

    #[Test]
    public function ロック待ちがタイムアウトしたら利用者にメッセージを返す(): void
    {
        $reservation = $this->createReservation();
        $form = $this->openEdit($reservation, 'B');

        // 2本目の接続で行ロックを取り、コミットせずに持ち続ける
        $other = DB::connection('other');
        $other->beginTransaction();
        $other->table('reservations')->where('id', $reservation->id)->lockForUpdate()->first();

        // テストを速くするため、この接続のロック待ちの上限を 10 秒から 1 秒にする
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $this->save($reservation, $form, ['number_of_people' => 9], 'B')
            ->assertRedirect(route('reservations.edit', $reservation))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'ロック待ちタイムアウト'));

        $other->rollBack();
        $this->assertSame(2, $reservation->refresh()->number_of_people);
    }

    #[Test]
    public function ロック中でも通常の_SELECT_は待たされず_コミット済みの値を読む(): void
    {
        $reservation = $this->createReservation();

        // 2本目の接続がロックを取り、人数を 9 に変えたがまだコミットしていない
        $other = DB::connection('other');
        $other->beginTransaction();
        $other->table('reservations')->where('id', $reservation->id)->lockForUpdate()->first();
        $other->table('reservations')->where('id', $reservation->id)->update(['number_of_people' => 9]);

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $startedAt = microtime(true);

        // 詳細画面（ロックを取らない通常の SELECT）は待たされず、コミット前の値は見えない
        $this->get(route('reservations.show', $reservation))
            ->assertOk()
            ->assertSee('<dd>2名</dd>', false);

        $this->assertLessThan(1.0, microtime(true) - $startedAt);
        $other->rollBack();
    }

    /**
     * 別プロセスで「ロックを取って、しばらく持ってから更新してコミットする」処理を起動する。
     */
    private function startLockHolder(int $reservationId, string $lockedMarker, int $holdSeconds): Process
    {
        $db = config('database.connections.mysql');

        $code = <<<'PHP'
            [$dsn, $user, $password, $id, $marker, $hold] = array_slice($argv, 1);
            $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->beginTransaction();
            $pdo->query("select * from reservations where id = {$id} for update")->fetch();
            touch($marker);                       // ロックを取ったことをテスト本体に知らせる
            sleep((int) $hold);
            $pdo->exec("update reservations set number_of_people = 8, version = version + 1 where id = {$id}");
            $pdo->commit();
            PHP;

        $process = new Process([
            PHP_BINARY, '-r', $code, '--',
            "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']}",
            $db['username'], $db['password'], (string) $reservationId, $lockedMarker, (string) $holdSeconds,
        ]);
        $process->start();

        return $process;
    }

    private function waitUntil(callable $condition, string $description, float $timeoutSeconds = 5): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                $this->fail("タイムアウト: {$description}");
            }
            usleep(20_000);
        }
    }
}
