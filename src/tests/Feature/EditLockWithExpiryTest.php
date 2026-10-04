<?php

namespace Tests\Feature;

use App\Enums\LockMode;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithReservations;
use Tests\TestCase;

/**
 * Phase 7: 有効期限付き編集ロック
 *
 * 期限の計算と比較は MySQL の NOW() で行っているので、Laravel の travelTo() では時間を進められない。
 * 期限切れの状態は、locked_until を過去の時刻に書き換えて作る。
 */
class EditLockWithExpiryTest extends TestCase
{
    use InteractsWithReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLockMode(LockMode::EditLockWithExpiry);
        config(['exclusive_control.edit_lock_ttl_seconds' => 60]);
    }

    #[Test]
    public function 編集画面を開くと有効期限付きのロックを取得する(): void
    {
        $reservation = $this->createReservation();

        $this->openEdit($reservation, 'A');

        $reservation->refresh();
        $this->assertSame('A', $reservation->locked_by);
        $this->assertSame(60, (int) $reservation->locked_at->diffInSeconds($reservation->locked_until));
    }

    #[Test]
    public function 期限内なら他の人はロックを奪えない(): void
    {
        $reservation = $this->createReservation();
        $this->openEdit($reservation, 'A');

        $this->asOperator('B')->get(route('reservations.edit', $reservation))
            ->assertRedirect(route('reservations.show', $reservation));
    }

    #[Test]
    public function 期限切れのロックは他の人が奪える(): void
    {
        $reservation = $this->createReservation();
        $this->openEdit($reservation, 'A');
        $this->expireLock($reservation);

        $this->openEdit($reservation, 'B');

        $reservation->refresh();
        $this->assertSame('B', $reservation->locked_by);
        $this->assertTrue($reservation->locked_until->isFuture(), 'B のロックには新しい期限が付く');
    }

    #[Test]
    public function 期限切れの後に奪われたら元の人の保存は拒否する(): void
    {
        $reservation = $this->createReservation();
        $formA = $this->openEdit($reservation, 'A');
        $this->expireLock($reservation);
        $formB = $this->openEdit($reservation, 'B');

        $this->save($reservation, $formA, ['number_of_people' => 4], 'A')
            ->assertRedirect(route('reservations.show', $reservation))
            ->assertSessionHas('error', fn ($message) => str_contains($message, '現在は B さんが編集中'));

        $this->save($reservation, $formB, ['status' => 'confirmed'], 'B')
            ->assertRedirect(route('reservations.show', $reservation));

        $reservation->refresh();
        $this->assertSame(2, $reservation->number_of_people, '期限切れの A の古い内容は書き込まれない');
        $this->assertSame('confirmed', $reservation->status->value);
    }

    #[Test]
    public function 期限切れでも誰も奪っていなければ保存できる(): void
    {
        $reservation = $this->createReservation();
        $form = $this->openEdit($reservation, 'A');
        $this->expireLock($reservation);

        $this->save($reservation, $form, ['number_of_people' => 4], 'A')
            ->assertRedirect(route('reservations.show', $reservation));

        $this->assertSame(4, $reservation->refresh()->number_of_people);
        $this->assertNull($reservation->locked_by);
    }

    #[Test]
    public function 編集画面を開き直すと期限が延長される(): void
    {
        $reservation = $this->createReservation();
        $this->openEdit($reservation, 'A');

        // 残り 5 秒の状態にする
        DB::table('reservations')->where('id', $reservation->id)
            ->update(['locked_until' => DB::raw('NOW() + INTERVAL 5 SECOND')]);

        $this->openEdit($reservation, 'A');

        $remaining = DB::table('reservations')->where('id', $reservation->id)
            ->value(DB::raw('TIMESTAMPDIFF(SECOND, NOW(), locked_until)'));
        $this->assertGreaterThanOrEqual(59, $remaining);
    }

    #[Test]
    public function 編集画面に有効期限と残り秒数を表示する(): void
    {
        $reservation = $this->createReservation();

        $this->asOperator('A')->get(route('reservations.edit', $reservation))
            ->assertSee('有効期限:')
            ->assertSee('id="lock-remaining"', false);
    }

    /**
     * ロックの期限を過去にする（時間が経って期限切れになった状態を作る）
     */
    private function expireLock(Reservation $reservation): void
    {
        DB::table('reservations')->where('id', $reservation->id)
            ->update(['locked_until' => DB::raw('NOW() - INTERVAL 1 SECOND')]);
    }
}
