<?php

namespace Tests\Feature;

use App\Enums\LockMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithReservations;
use Tests\TestCase;

/**
 * Phase 4: updated_at を利用した楽観的ロック
 */
class UpdatedAtLockTest extends TestCase
{
    use InteractsWithReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLockMode(LockMode::UpdatedAt);
    }

    #[Test]
    public function 編集画面は開いた時点の_updated_at_を_hidden_に出力する(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $reservation = $this->createReservation();

        $form = $this->openEdit($reservation);

        $this->assertSame('2026-10-01 10:00:00', $form['original_updated_at']);
    }

    #[Test]
    public function 開いた後に他の人が更新していたら競合になり上書きしない(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $reservation = $this->createReservation();

        $formA = $this->openEdit($reservation, 'A');
        $formB = $this->openEdit($reservation, 'B');

        $this->travel(1)->seconds();
        $this->save($reservation, $formA, ['number_of_people' => 4], 'A')
            ->assertRedirect(route('reservations.show', $reservation));

        $this->travel(1)->seconds();
        $this->save($reservation, $formB, ['status' => 'confirmed'], 'B')
            ->assertRedirect(route('reservations.edit', $reservation))
            ->assertSessionHas('conflict', true)
            ->assertSessionHas('error', fn ($message) => str_contains($message, '他の人が更新しました'));

        $reservation->refresh();
        $this->assertSame(4, $reservation->number_of_people, 'A の変更が守られる');
        $this->assertSame('pending', $reservation->status->value);
    }

    #[Test]
    public function 競合した後に最新の内容で開き直せば保存できる(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $reservation = $this->createReservation();
        $formA = $this->openEdit($reservation, 'A');
        $formB = $this->openEdit($reservation, 'B');

        $this->travel(1)->seconds();
        $this->save($reservation, $formA, ['number_of_people' => 4], 'A');
        $this->save($reservation, $formB, ['status' => 'confirmed'], 'B')->assertSessionHas('conflict');

        // 競合後の編集画面: 入力欄には B の入力（2名）が残り、hidden は最新の updated_at になる
        $this->travel(1)->seconds();
        $formB = $this->openEdit($reservation, 'B');
        $this->assertSame('2', $formB['number_of_people'], '入力欄には B の入力が残る（最新の 4名 は比較表に出る）');
        $this->assertSame('2026-10-01 10:00:01', $formB['original_updated_at'], 'hidden は old() ではなく DB の最新値');

        // B は比較表を見て人数を 4名 に直し、ステータスを確定にして保存し直す
        $this->save($reservation, $formB, ['number_of_people' => 4, 'status' => 'confirmed'], 'B')
            ->assertRedirect(route('reservations.show', $reservation));

        $reservation->refresh();
        $this->assertSame(4, $reservation->number_of_people);
        $this->assertSame('confirmed', $reservation->status->value);
    }

    #[Test]
    public function 競合時の編集画面に最新の内容とあなたの入力が並んで表示される(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $reservation = $this->createReservation();
        $formA = $this->openEdit($reservation, 'A');
        $formB = $this->openEdit($reservation, 'B');

        $this->travel(1)->seconds();
        $this->save($reservation, $formA, ['number_of_people' => 4], 'A');

        // リダイレクト先の編集画面までたどる
        $this->followingRedirects()
            ->save($reservation, $formB, ['status' => 'confirmed'], 'B')
            ->assertSee('他の人の更新後の最新内容')
            ->assertSeeInOrder(['人数', '4', '2']);
    }

    #[Test]
    public function 弱点_同じ秒の中で更新されると競合を見逃す(): void
    {
        // Laravel の時刻を止めて、すべての操作を「同じ秒」の中で行う
        $this->travelTo('2026-10-01 10:00:00');
        $reservation = $this->createReservation();

        $formA = $this->openEdit($reservation, 'A');
        $this->save($reservation, $formA, ['number_of_people' => 4], 'A');

        $formB = $this->openEdit($reservation, 'B');        // updated_at = 10:00:00 を受け取る

        $formA = $this->openEdit($reservation, 'A');
        $this->save($reservation, $formA, ['number_of_people' => 6], 'A');   // updated_at は 10:00:00 のまま

        $this->save($reservation, $formB, ['status' => 'confirmed'], 'B')
            ->assertRedirect(route('reservations.show', $reservation));     // 競合にならない

        $this->assertSame(4, $reservation->refresh()->number_of_people, 'A が 6名 にした変更が消える');
    }

    #[Test]
    public function 同じ秒に変更なしで保存しても競合にならない(): void
    {
        // ATTR_FOUND_ROWS が無いと、値が1つも変わらない UPDATE は 0 件と数えられ、競合と誤判定される
        $this->travelTo('2026-10-01 10:00:00');
        $reservation = $this->createReservation();

        $form = $this->openEdit($reservation);

        $this->save($reservation, $form)
            ->assertRedirect(route('reservations.show', $reservation));
    }

    #[Test]
    public function MySQL_の更新件数は設定によって意味が変わる(): void
    {
        // ATTR_FOUND_ROWS を設定していない接続を用意して比べる
        config(['database.connections.mysql_changed_rows' => [
            ...config('database.connections.mysql'),
            'options' => [],
        ]]);

        $reservation = $this->createReservation();
        $sameValue = fn ($connection) => DB::connection($connection)
            ->table('reservations')
            ->where('id', $reservation->id)
            ->update(['number_of_people' => 2]);   // 今と同じ値

        // RefreshDatabase のトランザクションの外なので、行は別接続からも見えるようコミット済みである必要がある
        DB::commit();

        try {
            $this->assertSame(1, $sameValue('mysql'), 'ATTR_FOUND_ROWS あり: WHERE に一致した件数');
            $this->assertSame(0, $sameValue('mysql_changed_rows'), 'ATTR_FOUND_ROWS なし: 値が変わった件数');
        } finally {
            // RefreshDatabase が最後にロールバックできるよう、トランザクションを張り直して後始末する
            DB::table('reservations')->delete();
            DB::beginTransaction();
        }
    }

    #[Test]
    public function hidden_の_updated_at_が不正なら保存しない(): void
    {
        $reservation = $this->createReservation();
        $form = $this->openEdit($reservation);

        $this->save($reservation, $form, ['original_updated_at' => 'invalid', 'number_of_people' => 9])
            ->assertSessionHasErrors('original_updated_at');

        $this->assertSame(2, $reservation->refresh()->number_of_people);
    }
}
