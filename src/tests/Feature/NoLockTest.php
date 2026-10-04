<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithReservations;
use Tests\TestCase;

/**
 * Phase 3: 排他制御なし
 *
 * 「Lost Update が起きること」をテストしている。
 * 不具合を固定するテストではなく、他の方式と比べるための基準として、この方式の性質を記録している。
 */
class NoLockTest extends TestCase
{
    use InteractsWithReservations, RefreshDatabase;

    #[Test]
    public function 後から保存した人の内容で先の変更が消える_LostUpdate(): void
    {
        $reservation = $this->createReservation();   // 2名 / 仮予約

        $formA = $this->openEdit($reservation, 'A');
        $formB = $this->openEdit($reservation, 'B');

        $this->save($reservation, $formA, ['number_of_people' => 4], 'A')
            ->assertRedirect(route('reservations.show', $reservation));

        // B はステータスだけを変えたつもりだが、フォームには開いた時点の「2名」が残っている
        $this->save($reservation, $formB, ['status' => 'confirmed'], 'B')
            ->assertRedirect(route('reservations.show', $reservation));

        $reservation->refresh();
        $this->assertSame(2, $reservation->number_of_people, 'A が変更した 4名 が B の保存で 2名 に戻る');
        $this->assertSame('confirmed', $reservation->status->value);
    }
}
