<?php

namespace Tests\Feature;

use App\Enums\LockMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithReservations;
use Tests\TestCase;

/**
 * Phase 7: 編集ロック（有効期限なし）
 */
class EditLockTest extends TestCase
{
    use InteractsWithReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLockMode(LockMode::EditLock);
    }

    #[Test]
    public function 編集画面を開くとロックを取得する(): void
    {
        $reservation = $this->createReservation();

        $this->openEdit($reservation, 'A');

        $reservation->refresh();
        $this->assertSame('A', $reservation->locked_by);
        $this->assertNotNull($reservation->locked_at);
        $this->assertNull($reservation->locked_until, '有効期限なしの方式では locked_until を使わない');
    }

    #[Test]
    public function 他の人がロック中なら編集画面に入れない(): void
    {
        $reservation = $this->createReservation();
        $this->openEdit($reservation, 'A');

        $this->asOperator('B')->get(route('reservations.edit', $reservation))
            ->assertRedirect(route('reservations.show', $reservation))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'A さんが編集中'));

        $this->assertSame('A', $reservation->refresh()->locked_by, 'B はロックを奪えない');
    }

    #[Test]
    public function ロックの取得とキャンセルでは_updated_at_も_version_も変わらない(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $reservation = $this->createReservation();
        $this->travelBack();

        $form = $this->openEdit($reservation, 'A');
        $this->asOperator('A')->delete(route('reservations.edit-lock.release', $reservation));

        $reservation->refresh();
        $this->assertSame('2026-10-01 10:00:00', $reservation->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $reservation->version);
    }

    #[Test]
    public function 保存するとロックが外れ他の人が編集できるようになる(): void
    {
        $reservation = $this->createReservation();
        $form = $this->openEdit($reservation, 'A');

        $this->save($reservation, $form, ['number_of_people' => 4], 'A')
            ->assertRedirect(route('reservations.show', $reservation));

        $reservation->refresh();
        $this->assertSame(4, $reservation->number_of_people);
        $this->assertNull($reservation->locked_by);
        $this->assertNull($reservation->locked_at);

        $this->openEdit($reservation, 'B');
        $this->assertSame('B', $reservation->refresh()->locked_by);
    }

    #[Test]
    public function キャンセルすると自分のロックだけが外れる(): void
    {
        $reservation = $this->createReservation();
        $this->openEdit($reservation, 'A');

        // B がキャンセルを送っても A のロックは外れない（条件が locked_by = 自分 のため）
        $this->asOperator('B')->delete(route('reservations.edit-lock.release', $reservation));
        $this->assertSame('A', $reservation->refresh()->locked_by);

        $this->asOperator('A')->delete(route('reservations.edit-lock.release', $reservation))
            ->assertRedirect(route('reservations.show', $reservation));
        $this->assertNull($reservation->refresh()->locked_by);
    }

    #[Test]
    public function 弱点_放置されたロックは強制解除するまで残る(): void
    {
        $reservation = $this->createReservation();
        $this->openEdit($reservation, 'A');      // A はキャンセルせずにブラウザを閉じた

        $this->travel(1)->days();                // 1日たっても
        $this->asOperator('B')->get(route('reservations.edit', $reservation))
            ->assertRedirect(route('reservations.show', $reservation));

        $this->asOperator('B')->delete(route('reservations.edit-lock.force', $reservation))
            ->assertSessionHas('success', 'A さんの編集ロックを強制解除しました。');

        $this->openEdit($reservation, 'B');
        $this->assertSame('B', $reservation->refresh()->locked_by);
    }

    #[Test]
    public function ロックを失った後の保存は拒否し詳細画面にメッセージを出す(): void
    {
        $reservation = $this->createReservation();
        $formA = $this->openEdit($reservation, 'A');

        // 強制解除された後に B がロックを取った
        $this->delete(route('reservations.edit-lock.force', $reservation));
        $this->openEdit($reservation, 'B');

        $this->save($reservation, $formA, ['number_of_people' => 4], 'A')
            ->assertRedirect(route('reservations.show', $reservation))
            ->assertSessionHas('error', fn ($message) => str_contains($message, '編集ロックが失われました。現在は B さんが編集中'));

        $reservation->refresh();
        $this->assertSame(2, $reservation->number_of_people);
        $this->assertSame('B', $reservation->locked_by, 'B のロックはそのまま');
    }

    #[Test]
    public function 操作者はフォームの入力ではなくセッションから決まる(): void
    {
        $reservation = $this->createReservation();
        $formA = $this->openEdit($reservation, 'A');

        // B が A の名前を送っても、セッションの操作者は B なので保存できない
        $this->save($reservation, [...$formA, 'operator' => 'A', 'operator_name' => 'A'], ['number_of_people' => 9], 'B')
            ->assertSessionHas('error');

        $this->assertSame(2, $reservation->refresh()->number_of_people);
    }

    #[Test]
    public function 有効期限なしの方式では_locked_until_が過ぎていてもロックを奪えない(): void
    {
        $reservation = $this->createReservation([
            'locked_by' => 'A',
            'locked_at' => now()->subHour(),
            'locked_until' => now()->subMinutes(30),   // 期限付きの方式で取られたロックが残っている
        ]);

        $this->asOperator('B')->get(route('reservations.edit', $reservation))
            ->assertRedirect(route('reservations.show', $reservation));
    }

    #[Test]
    public function 弱点_削除は編集ロックを無視する(): void
    {
        $reservation = $this->createReservation();
        $this->openEdit($reservation, 'A');

        // 編集ロックはアプリのルールなので、ルールを確認しない処理からは守れない
        $this->asOperator('B')->delete(route('reservations.destroy', $reservation));

        $this->assertModelMissing($reservation);
    }
}
