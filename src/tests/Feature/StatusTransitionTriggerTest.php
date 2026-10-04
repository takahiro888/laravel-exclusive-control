<?php

namespace Tests\Feature;

use App\Enums\BatchStrategy;
use App\Enums\ReservationStatus;
use App\Services\Batch\ReservationStatusBatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithReservations;
use Tests\TestCase;

/**
 * Phase 10: 状態遷移のルールを DB トリガーで強制する（最後の砦）
 *
 * トリガーの内容:
 *   status が変わる UPDATE のうち、許された遷移（仮予約→確定、仮予約→キャンセル、確定→キャンセル）以外は
 *   SIGNAL でエラーにして、UPDATE そのものを失敗させる。
 *
 * VersionTriggerTest の「version を上げるトリガー」との違い:
 *   version のトリガーは「変更があったことを知らせる」だけで、後出しの UPDATE 自体は成功させてしまう。
 *   このトリガーは「キャンセル済み → 確定」のような、どの順番でも起きてはいけない変更そのものを DB が拒否する。
 *   書いた処理が排他制御をしていなくても、生の SQL でも、同じように拒否される。
 *
 * ロックは取らない（UPDATE 1 行ごとに条件を評価するだけ）ので、利用者を待たせることはない。
 *
 * DatabaseTruncation と root 接続を使う理由は VersionTriggerTest と同じ。
 */
class StatusTransitionTriggerTest extends TestCase
{
    use DatabaseTruncation, InteractsWithReservations;

    private const TRIGGER = 'reservations_guard_status_transition';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.mysql_root' => [
            ...config('database.connections.mysql'),
            'username' => 'root',
            'password' => env('DB_ROOT_PASSWORD', 'root'),
        ]]);

        DB::connection('mysql_root')->unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);
        DB::connection('mysql_root')->unprepared("
            CREATE TRIGGER ".self::TRIGGER." BEFORE UPDATE ON reservations
            FOR EACH ROW
            BEGIN
                IF NEW.status <> OLD.status AND NOT (
                       (OLD.status = 'pending'   AND NEW.status IN ('confirmed', 'cancelled'))
                    OR (OLD.status = 'confirmed' AND NEW.status = 'cancelled')
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'invalid reservation status transition';
                END IF;
            END
        ");
    }

    protected function tearDown(): void
    {
        DB::connection('mysql_root')->unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);
        DB::table('reservations')->truncate();
        DB::purge('mysql_root');

        parent::tearDown();
    }

    #[Test]
    public function 排他制御なしのバッチの後出しでもキャンセルは覆らない(): void
    {
        $reservation = $this->createReservation();

        try {
            app(ReservationStatusBatch::class)->run(
                ReservationStatus::Pending, ReservationStatus::Confirmed, BatchStrategy::None, [$reservation->id],
                afterRead: fn () => $this->post(route('reservations.cancel', $reservation), ['original_version' => 1])
                    ->assertSessionHas('success'),
            );
            $this->fail('バッチの UPDATE は DB に拒否されるはず');
        } catch (QueryException $e) {
            $this->assertSame('45000', $e->errorInfo[0], 'トリガーの SIGNAL によるエラー');
        }

        $this->assertSame('cancelled', $reservation->refresh()->status->value, 'キャンセルのまま');
    }

    #[Test]
    public function 生の_SQL_でもキャンセル済みは変更できない(): void
    {
        $reservation = $this->createReservation(['status' => ReservationStatus::Cancelled]);

        $this->expectException(QueryException::class);
        DB::update("update reservations set status = 'confirmed' where id = ?", [$reservation->id]);
    }

    #[Test]
    public function 確定を仮予約に戻すことも拒否する(): void
    {
        // CombinationTest の場面2（利用者の古いフォームで確定が仮予約に戻る）も、DB のレベルで止められる
        $reservation = $this->createReservation(['status' => ReservationStatus::Confirmed]);

        $this->expectException(QueryException::class);
        DB::table('reservations')->where('id', $reservation->id)->update(['status' => 'pending']);
    }

    #[Test]
    public function 許された遷移とステータス以外の変更は通る(): void
    {
        $pending = $this->createReservation();
        DB::table('reservations')->where('id', $pending->id)->update(['status' => 'confirmed']);
        DB::table('reservations')->where('id', $pending->id)->update(['status' => 'cancelled']);
        $this->assertSame('cancelled', $pending->refresh()->status->value);

        // キャンセル済みでも、ステータス以外（顧客名など）の変更は止めない
        DB::table('reservations')->where('id', $pending->id)->update(['customer_name' => '山田 次郎']);
        $this->assertSame('山田 次郎', $pending->refresh()->customer_name);

        // 推奨の書き方（状態ガード + version）のバッチは、そもそも違反する UPDATE を発行しないのでエラーにならない
        $other = $this->createReservation();
        $result = app(ReservationStatusBatch::class)->run(
            ReservationStatus::Pending, ReservationStatus::Confirmed, BatchStrategy::StateGuardWithVersion, [$other->id],
            afterRead: fn () => $this->post(route('reservations.cancel', $other), ['original_version' => 1]),
        );
        $this->assertArrayHasKey($other->id, $result->skipped);
        $this->assertSame('cancelled', $other->refresh()->status->value);
    }

    #[Test]
    public function 注意_排他制御なしのバッチは違反した行でエラーになり_残りの行を処理せずに止まる(): void
    {
        $first = $this->createReservation();
        $second = $this->createReservation();
        $third = $this->createReservation();

        try {
            app(ReservationStatusBatch::class)->run(
                ReservationStatus::Pending, ReservationStatus::Confirmed, BatchStrategy::None,
                [$first->id, $second->id, $third->id],
                afterRead: fn () => DB::table('reservations')->where('id', $second->id)->update(['status' => 'cancelled']),
            );
        } catch (QueryException) {
            // 2 件目で止まる
        }

        $this->assertSame('confirmed', $first->refresh()->status->value, '1 件目は確定済み');
        $this->assertSame('cancelled', $second->refresh()->status->value, '2 件目はキャンセルが守られた');
        $this->assertSame('pending', $third->refresh()->status->value, '3 件目は処理されずに残る');
    }
}
