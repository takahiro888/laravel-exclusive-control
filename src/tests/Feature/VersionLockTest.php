<?php

namespace Tests\Feature;

use App\Enums\LockMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithReservations;
use Tests\TestCase;

/**
 * Phase 5: version カラムを利用した楽観的ロック
 */
class VersionLockTest extends TestCase
{
    use InteractsWithReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLockMode(LockMode::Version);
    }

    #[Test]
    public function 保存するたびに_version_が1増える(): void
    {
        $reservation = $this->createReservation();

        $this->save($reservation, $this->openEdit($reservation), ['number_of_people' => 3]);
        $this->assertSame(2, $reservation->refresh()->version);

        $this->save($reservation, $this->openEdit($reservation), ['number_of_people' => 4]);
        $this->assertSame(3, $reservation->refresh()->version);
    }

    #[Test]
    public function 開いた後に他の人が更新していたら競合になり上書きしない(): void
    {
        $reservation = $this->createReservation();

        $formA = $this->openEdit($reservation, 'A');
        $formB = $this->openEdit($reservation, 'B');
        $this->assertSame('1', $formB['original_version']);

        $this->save($reservation, $formA, ['number_of_people' => 4], 'A')
            ->assertRedirect(route('reservations.show', $reservation));

        $this->save($reservation, $formB, ['status' => 'confirmed'], 'B')
            ->assertRedirect(route('reservations.edit', $reservation))
            ->assertSessionHas('conflict', true);

        $reservation->refresh();
        $this->assertSame(4, $reservation->number_of_people);
        $this->assertSame('pending', $reservation->status->value);
        $this->assertSame(2, $reservation->version, '競合した B の保存では version は増えない');
    }

    #[Test]
    public function 同じ秒の中で更新されても競合を検知する(): void
    {
        // UpdatedAtLockTest の「弱点_同じ秒の中で更新されると競合を見逃す」とまったく同じ手順
        $this->travelTo('2026-10-01 10:00:00');
        $reservation = $this->createReservation();

        $formA = $this->openEdit($reservation, 'A');
        $this->save($reservation, $formA, ['number_of_people' => 4], 'A');

        $formB = $this->openEdit($reservation, 'B');        // version = 2 を受け取る

        $formA = $this->openEdit($reservation, 'A');
        $this->save($reservation, $formA, ['number_of_people' => 6], 'A');   // version = 3 になる

        $this->save($reservation, $formB, ['status' => 'confirmed'], 'B')
            ->assertRedirect(route('reservations.edit', $reservation));     // 競合になる

        $this->assertSame(6, $reservation->refresh()->number_of_people, 'A の 6名 が守られる');
    }

    #[Test]
    public function version_を改ざんしても保存できない(): void
    {
        $reservation = $this->createReservation();
        $form = $this->openEdit($reservation);

        // 数値でない値は形式エラー
        $this->save($reservation, $form, ['original_version' => 'abc', 'number_of_people' => 9])
            ->assertSessionHasErrors('original_version');

        // DB と一致しない数値は競合
        $this->save($reservation, $form, ['original_version' => 999, 'number_of_people' => 9])
            ->assertSessionHas('conflict', true);

        $this->assertSame(2, $reservation->refresh()->number_of_people);
        $this->assertSame(1, $reservation->version);
    }
}
