<?php

namespace Tests\Feature;

use App\Enums\BatchStrategy;
use App\Enums\LockMode;
use App\Enums\ReservationStatus;
use App\Services\Batch\ReservationStatusBatch;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithReservations;
use Tests\TestCase;

/**
 * Phase 10: version を上げるトリガーで「上げ忘れ」を DB 側で防げるか
 *
 * トリガーの内容:
 *   reservations の業務データ（顧客名・人数・予約日時・ステータス）が変わる UPDATE では、
 *   アプリが何を SET したかに関係なく version = 変更前の version + 1 にする。
 *   ロックのカラム（locked_*）だけが変わる UPDATE では version を変えない。
 *
 * なぜ DatabaseTruncation なのか:
 *   CREATE TRIGGER は DDL なので、MySQL は実行時にトランザクションを暗黙にコミットする。
 *   RefreshDatabase のトランザクションが途中で切れてしまうため使えない。
 *
 * なぜ root で接続するのか:
 *   バイナリログが有効な MySQL では、SUPER 権限の無いユーザーはトリガーを作れない（エラー 1419）。
 *   本番でトリガーを使う場合も、DBA の権限や運用との調整が必要になる。
 */
class VersionTriggerTest extends TestCase
{
    use DatabaseTruncation, InteractsWithReservations;

    private const TRIGGER = 'reservations_bump_version';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.mysql_root' => [
            ...config('database.connections.mysql'),
            'username' => 'root',
            'password' => env('DB_ROOT_PASSWORD', 'root'),
        ]]);

        DB::connection('mysql_root')->unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);
        DB::connection('mysql_root')->unprepared('
            CREATE TRIGGER '.self::TRIGGER.' BEFORE UPDATE ON reservations
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.customer_name <=> OLD.customer_name
                    AND NEW.number_of_people <=> OLD.number_of_people
                    AND NEW.reservation_date <=> OLD.reservation_date
                    AND NEW.status <=> OLD.status) THEN
                    SET NEW.version = OLD.version + 1;
                END IF;
            END
        ');
    }

    protected function tearDown(): void
    {
        DB::connection('mysql_root')->unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);
        DB::table('reservations')->truncate();
        DB::purge('mysql_root');

        parent::tearDown();
    }

    #[Test]
    public function version_を上げない処理でも_業務データが変われば_version_が上がる(): void
    {
        $reservation = $this->createReservation();

        // 生の SQL で、version に触れずにステータスを変える
        DB::update("update reservations set status = 'confirmed' where id = ?", [$reservation->id]);

        $this->assertSame(2, $reservation->refresh()->version);
    }

    #[Test]
    public function アプリが_version_を上げても二重には上がらない(): void
    {
        $this->useLockMode(LockMode::Version);
        $reservation = $this->createReservation();

        // VersionLockUpdater は version = version + 1 を SET するが、トリガーが OLD + 1 で上書きする
        $this->save($reservation, $this->openEdit($reservation), ['number_of_people' => 4]);

        $this->assertSame(2, $reservation->refresh()->version);
    }

    #[Test]
    public function ロックのカラムだけの更新では_version_は上がらない(): void
    {
        $this->useLockMode(LockMode::EditLock);
        $reservation = $this->createReservation();

        $this->openEdit($reservation, 'A');      // locked_by などだけを UPDATE する

        $this->assertSame(1, $reservation->refresh()->version);
    }

    #[Test]
    public function 効く_version_を上げないバッチの変更に利用者が気づけるようになる(): void
    {
        // CombinationTest の場面2（なしのバッチでは利用者の古いフォームで確定が戻った）と同じ手順
        $this->useLockMode(LockMode::Version);
        $reservation = $this->createReservation();

        $form = $this->openEdit($reservation, 'A');
        app(ReservationStatusBatch::class)->run(
            ReservationStatus::Pending, ReservationStatus::Confirmed, BatchStrategy::None, [$reservation->id]
        );

        $this->save($reservation, $form, ['number_of_people' => 4], 'A')
            ->assertSessionHas('conflict', true);
        $this->assertSame('confirmed', $reservation->refresh()->status->value);
    }

    #[Test]
    public function 効かない_バッチの後出しは防げない(): void
    {
        // CombinationTest の場面1 と同じ手順。トリガーは version を上げるだけで、
        // 「読んだ時点の前提が変わっていたら書かない」という判断はしないので、キャンセルは上書きされる。
        $reservation = $this->createReservation();

        app(ReservationStatusBatch::class)->run(
            ReservationStatus::Pending, ReservationStatus::Confirmed, BatchStrategy::None, [$reservation->id],
            afterRead: fn () => $this->post(route('reservations.cancel', $reservation), ['original_version' => 1]),
        );

        $reservation->refresh();
        $this->assertSame('confirmed', $reservation->status->value, 'キャンセルが確定で上書きされる');
        $this->assertSame(3, $reservation->version, 'version は上がっている（キャンセルで 2、バッチで 3）');
    }
}
