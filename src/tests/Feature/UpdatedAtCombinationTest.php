<?php

namespace Tests\Feature;

use App\Enums\BatchStrategy;
use App\Enums\LockMode;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\Batch\ReservationStatusBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithReservations;
use Tests\TestCase;

/**
 * Phase 10: 画面が updated_at 方式の楽観的ロックの場合の、バッチとの組み合わせ
 *
 * 現場では version カラムが無く、updated_at で楽観的ロックをしていることが多い。
 * その場合にバッチの書き方で何が変わるかを確認する。
 *
 * 時刻は travelTo() で止め、「別の秒」「同じ秒」を作り分けている。
 */
class UpdatedAtCombinationTest extends TestCase
{
    use InteractsWithReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLockMode(LockMode::UpdatedAt);
        $this->travelTo('2026-10-01 10:00:00');
    }

    #[Test]
    public function 画面が_updated_at_で確認していても_排他制御なしのバッチの後出しは防げない(): void
    {
        $reservation = $this->createReservation();

        $this->runConfirmBatch(BatchStrategy::None, $reservation, afterRead: function () use ($reservation) {
            $this->travel(1)->seconds();
            // 利用者が編集画面でキャンセルにする（where updated_at = 開いた時点 で確認して成功する）
            $this->save($reservation, $this->openEdit($reservation), ['status' => 'cancelled'])
                ->assertRedirect(route('reservations.show', $reservation));
            $this->assertSame('cancelled', $reservation->refresh()->status->value);
            $this->travel(1)->seconds();
        });

        // バッチの UPDATE は where id = ? だけなので、updated_at が何であっても一致する
        $this->assertSame('confirmed', $reservation->refresh()->status->value, 'キャンセルが確定で上書きされる');
    }

    #[Test]
    public function 状態ガードのバッチなら後出しを防げる(): void
    {
        $reservation = $this->createReservation();

        $result = $this->runConfirmBatch(BatchStrategy::StateGuard, $reservation, afterRead: function () use ($reservation) {
            $this->travel(1)->seconds();
            $this->save($reservation, $this->openEdit($reservation), ['status' => 'cancelled']);
            $this->travel(1)->seconds();
        });

        $this->assertSame('cancelled', $reservation->refresh()->status->value);
        $this->assertArrayHasKey($reservation->id, $result->skipped);
    }

    #[Test]
    public function Eloquent_で書くバッチなら_updated_at_が変わるので利用者は気づける(): void
    {
        $reservation = $this->createReservation();
        $form = $this->openEdit($reservation);                 // updated_at = 10:00:00

        $this->travel(1)->seconds();
        // 状態ガードのバッチ。Eloquent の Builder::update() が updated_at を 10:00:01 に更新する
        $this->runConfirmBatch(BatchStrategy::StateGuard, $reservation);
        $this->travel(1)->seconds();

        $this->save($reservation, $form, ['number_of_people' => 4])
            ->assertSessionHas('conflict', true);
        $this->assertSame('confirmed', $reservation->refresh()->status->value);
    }

    #[Test]
    public function 弱点_updated_at_を更新しないバッチの変更には気づけない(): void
    {
        $reservation = $this->createReservation();
        $form = $this->openEdit($reservation);

        $this->travel(1)->seconds();
        // DB::table() や生の SQL のバッチは updated_at を自動で更新しない（状態ガードは入れている）
        DB::table('reservations')->where('id', $reservation->id)->where('status', 'pending')
            ->update(['status' => 'confirmed']);
        $this->travel(1)->seconds();

        $this->save($reservation, $form, ['number_of_people' => 4])
            ->assertRedirect(route('reservations.show', $reservation));   // 競合にならない

        $this->assertSame('pending', $reservation->refresh()->status->value, 'バッチの確定が利用者の古いフォームで戻る');
    }

    #[Test]
    public function 弱点_利用者が画面を開いたのと同じ秒にバッチが書くと気づけない(): void
    {
        $reservation = $this->createReservation();
        $form = $this->openEdit($reservation);                 // updated_at = 10:00:00

        $this->runConfirmBatch(BatchStrategy::StateGuard, $reservation);   // 同じ秒なので updated_at = 10:00:00 のまま
        $this->travel(1)->seconds();

        $this->save($reservation, $form, ['number_of_people' => 4])
            ->assertRedirect(route('reservations.show', $reservation));

        $this->assertSame('pending', $reservation->refresh()->status->value);
    }

    private function runConfirmBatch(BatchStrategy $strategy, Reservation $reservation, ?\Closure $afterRead = null)
    {
        return app(ReservationStatusBatch::class)->run(
            ReservationStatus::Pending, ReservationStatus::Confirmed, $strategy, [$reservation->id], $afterRead,
        );
    }
}
