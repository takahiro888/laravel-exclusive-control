<?php

namespace Tests\Feature;

use App\Enums\LockMode;
use App\Services\LockModeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithReservations;
use Tests\TestCase;

/**
 * Phase 3: 排他制御方式の切り替え
 */
class LockModeTest extends TestCase
{
    use InteractsWithReservations, RefreshDatabase;

    #[Test]
    public function 初期状態は排他制御なし(): void
    {
        $this->assertSame(LockMode::None, app(LockModeSetting::class)->current());

        $this->get(route('reservations.index'))
            ->assertSee('現在の排他制御方式: <strong>排他制御なし</strong>', false);
    }

    #[Test]
    public function 方式を切り替えると全画面に表示される(): void
    {
        $this->from(route('reservations.index'))
            ->put(route('lock-mode.update'), ['lock_mode' => 'version'])
            ->assertRedirect(route('reservations.index'))
            ->assertSessionHas('success');

        $this->assertSame(LockMode::Version, app(LockModeSetting::class)->current());
        $this->get(route('reservations.index'))
            ->assertSee('現在の排他制御方式: <strong>楽観的ロック（version）</strong>', false);
    }

    #[Test]
    public function 存在しない方式には切り替えられない(): void
    {
        $this->put(route('lock-mode.update'), ['lock_mode' => 'unknown'])
            ->assertSessionHasErrors('lock_mode');

        $this->assertSame(LockMode::None, app(LockModeSetting::class)->current());
    }

    #[Test]
    public function 編集中に方式が切り替えられたら保存しない(): void
    {
        $reservation = $this->createReservation();
        $form = $this->openEdit($reservation);           // 「排他制御なし」で開く

        $this->useLockMode(LockMode::Version);            // 誰かが方式を切り替えた

        $this->save($reservation, $form, ['number_of_people' => 9])
            ->assertRedirect(route('reservations.edit', $reservation))
            ->assertSessionHas('error');

        $this->assertSame(2, $reservation->refresh()->number_of_people);
    }
}
