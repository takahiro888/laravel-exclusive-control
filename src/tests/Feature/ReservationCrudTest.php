<?php

namespace Tests\Feature;

use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithReservations;
use Tests\TestCase;

/**
 * Phase 2: 予約の CRUD
 */
class ReservationCrudTest extends TestCase
{
    // テストごとにトランザクションを張り、終了時にロールバックして DB を元に戻す
    use InteractsWithReservations, RefreshDatabase;

    #[Test]
    public function トップページは予約一覧に転送される(): void
    {
        $this->get('/')->assertRedirect('/reservations');
    }

    #[Test]
    public function 予約一覧に予約と排他制御用の値が表示される(): void
    {
        $reservation = $this->createReservation(['version' => 3, 'locked_by' => 'A']);

        $this->get(route('reservations.index'))
            ->assertOk()
            ->assertSee('山田 太郎')
            ->assertSee(route('reservations.edit', $reservation));
    }

    #[Test]
    public function 予約詳細に排他制御用カラムの値が表示される(): void
    {
        $reservation = $this->createReservation(['version' => 5]);

        $this->get(route('reservations.show', $reservation))
            ->assertOk()
            ->assertSee('山田 太郎')
            ->assertSee('<dt>version</dt><dd>5</dd>', false);
    }

    #[Test]
    public function 予約を登録できる(): void
    {
        $response = $this->post(route('reservations.store'), [
            'customer_name' => '鈴木 花子',
            'number_of_people' => 3,
            'reservation_date' => '2026-12-25T18:30',
            'status' => 'confirmed',
        ]);

        $reservation = Reservation::sole();
        $response->assertRedirect(route('reservations.show', $reservation));
        $this->assertSame('鈴木 花子', $reservation->customer_name);
        $this->assertSame('2026-12-25 18:30:00', $reservation->reservation_date->format('Y-m-d H:i:s'));
        // 排他制御用カラムは初期値になる
        $this->assertSame(1, $reservation->version);
        $this->assertNull($reservation->locked_by);
    }

    #[Test]
    public function 入力が不正なら登録せず日本語のエラーを返す(): void
    {
        $this->post(route('reservations.store'), [
            'customer_name' => '',
            'number_of_people' => 0,
            'reservation_date' => 'not-a-date',
            'status' => 'unknown',
        ])->assertSessionHasErrors([
            'customer_name' => '顧客名は必須です。',
            'number_of_people' => '人数は1以上で入力してください。',
            'reservation_date' => '予約日時は正しい日時で入力してください。',
            'status' => 'ステータスの値が不正です。',
        ]);

        $this->assertDatabaseCount('reservations', 0);
    }

    #[Test]
    public function フォームから排他制御用カラムは書き換えられない(): void
    {
        // $fillable に含めていないので、version や locked_by を送っても無視される
        $this->post(route('reservations.store'), [
            'customer_name' => '鈴木 花子',
            'number_of_people' => 3,
            'reservation_date' => '2026-12-25T18:30',
            'status' => 'confirmed',
            'version' => 999,
            'locked_by' => '攻撃者',
        ]);

        $reservation = Reservation::sole();
        $this->assertSame(1, $reservation->version);
        $this->assertNull($reservation->locked_by);
    }

    #[Test]
    public function 予約を更新できる(): void
    {
        $reservation = $this->createReservation();
        $form = $this->openEdit($reservation);

        $this->save($reservation, $form, ['number_of_people' => 4])
            ->assertRedirect(route('reservations.show', $reservation));

        $this->assertSame(4, $reservation->refresh()->number_of_people);
    }

    #[Test]
    public function 予約を削除できる(): void
    {
        $reservation = $this->createReservation();

        $this->delete(route('reservations.destroy', $reservation))
            ->assertRedirect(route('reservations.index'));

        $this->assertModelMissing($reservation);
    }
}
