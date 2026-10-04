<?php

namespace Tests\Feature;

use App\Enums\BatchStrategy;
use App\Enums\LockMode;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\Batch\ReservationStatusBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithReservations;
use Tests\TestCase;

/**
 * Phase 10: 異なる処理の組み合わせ
 *
 * 「楽観的ロックのキャンセル処理」「画面の編集（version 方式）」と、
 * 書き方の違う「ステータス一括変更バッチ」が同じ予約を処理したときの結果を、
 * バッチの書き方 × 場面 の組み合わせで検証する。
 *
 * バッチの「① 読み込み」と「② 書き込み」の間に割り込むため、ReservationStatusBatch の
 * afterRead フックの中で利用者の操作や別のバッチを実行している。
 */
class CombinationTest extends TestCase
{
    use InteractsWithReservations, RefreshDatabase;

    private const OK = 'OK';

    /**
     * 場面1: バッチの後出し
     *   確定バッチが仮予約を読み込む → 利用者がキャンセル（楽観的ロック）→ バッチが書き込む
     */
    #[Test]
    #[DataProvider('strategies')]
    public function 場面1_読み込んだ後に利用者がキャンセルした予約をバッチが確定するか(BatchStrategy $strategy, array $expected): void
    {
        $reservation = $this->createReservation();

        $result = $this->runConfirmBatch($strategy, $reservation, afterRead: function () use ($reservation) {
            $this->post(route('reservations.cancel', $reservation), ['original_version' => $reservation->version])
                ->assertSessionHas('success', '予約をキャンセルしました。');
        });

        $this->assertSame($expected['場面1'], $reservation->refresh()->status->value,
            "{$strategy->label()}: キャンセルした予約が最終的にどうなるか");

        if ($expected['場面1'] === 'cancelled') {
            $this->assertArrayHasKey($reservation->id, $result->skipped, 'バッチはこの予約をスキップしたと記録する');
        }
    }

    /**
     * 場面2: 利用者の後出し
     *   利用者が編集画面を開く（version 方式）→ 確定バッチが実行される → 利用者が人数だけ変えて保存
     */
    #[Test]
    #[DataProvider('strategies')]
    public function 場面2_編集画面を開いた後にバッチが確定した予約を利用者の保存で戻してしまうか(BatchStrategy $strategy, array $expected): void
    {
        $this->useLockMode(LockMode::Version);
        $reservation = $this->createReservation();

        $form = $this->openEdit($reservation, 'A');           // フォームの status は「仮予約」
        $this->runConfirmBatch($strategy, $reservation);       // バッチが確定にする
        $response = $this->save($reservation, $form, ['number_of_people' => 4], 'A');

        $reservation->refresh();
        if ($expected['場面2'] === self::OK) {
            $response->assertSessionHas('conflict', true);     // 利用者はバッチの変更に気づける
            $this->assertSame('confirmed', $reservation->status->value);
        } else {
            $this->assertSame($expected['場面2'], $reservation->status->value,
                "{$strategy->label()}: version が上がらないので、利用者の古いフォームで確定が戻る");
        }
    }

    /**
     * 場面3: 確定されたことを知らずにキャンセル
     *   利用者が詳細画面を開く → 確定バッチが実行される → 利用者がキャンセル
     *   （確定済みの予約のキャンセルは遷移ルール上は許されるが、キャンセル料などが発生する業務なら、利用者は知っているべき）
     */
    #[Test]
    #[DataProvider('strategies')]
    public function 場面3_画面を開いた後にバッチが確定したことに利用者が気づけるか(BatchStrategy $strategy, array $expected): void
    {
        $reservation = $this->createReservation();
        $versionOnScreen = $reservation->version;              // 詳細画面のキャンセルボタンが持つ version

        $this->runConfirmBatch($strategy, $reservation);
        $response = $this->post(route('reservations.cancel', $reservation), ['original_version' => $versionOnScreen]);

        if ($expected['場面3'] === self::OK) {
            $response->assertSessionHas('error', fn ($m) => str_contains($m, '現在のステータス: 確定'));
            $this->assertSame('confirmed', $reservation->refresh()->status->value);
        } else {
            $response->assertSessionHas('success');
            $this->assertSame('cancelled', $reservation->refresh()->status->value, '確定されたことを知らないままキャンセルが通る');
        }
    }

    /**
     * 場面4: バッチ同士
     *   確定バッチが読み込む → 自動キャンセルバッチ（同じ書き方）がキャンセル → 確定バッチが書き込む
     */
    #[Test]
    #[DataProvider('strategies')]
    public function 場面4_確定バッチと自動キャンセルバッチが同じ予約を処理したらどうなるか(BatchStrategy $strategy, array $expected): void
    {
        $reservation = $this->createReservation();

        $this->runConfirmBatch($strategy, $reservation, afterRead: function () use ($strategy, $reservation) {
            app(ReservationStatusBatch::class)->run(
                ReservationStatus::Pending, ReservationStatus::Cancelled, $strategy, [$reservation->id]
            );
        });

        $this->assertSame($expected['場面4'], $reservation->refresh()->status->value);
    }

    /**
     * 場面5: 関係のない項目の編集
     *   確定バッチが読み込む → 利用者が顧客名だけを変更（version 方式）→ バッチが書き込む
     *   ステータスには関係ない変更なので、バッチは確定してよい。
     */
    #[Test]
    #[DataProvider('strategies')]
    public function 場面5_読み込んだ後に顧客名だけが変わった予約をバッチが確定できるか(BatchStrategy $strategy, array $expected): void
    {
        $this->useLockMode(LockMode::Version);
        $reservation = $this->createReservation();

        $result = $this->runConfirmBatch($strategy, $reservation, afterRead: function () use ($reservation) {
            $form = $this->openEdit($reservation, 'A');
            $this->save($reservation, $form, ['customer_name' => '山田 次郎'], 'A')
                ->assertRedirect(route('reservations.show', $reservation));
        });

        $reservation->refresh();
        $this->assertSame('山田 次郎', $reservation->customer_name, 'どの書き方でも顧客名の変更は消えない');
        $this->assertSame($expected['場面5'], $reservation->status->value);

        if ($expected['場面5'] === 'pending') {
            $this->assertArrayHasKey($reservation->id, $result->skipped, '不要なスキップ（次回の実行まで確定が遅れる）');
        }
    }

    /**
     * 書き方ごとの期待値。
     * 'pending' / 'confirmed' / 'cancelled' は最終的なステータス、OK は「利用者が気づける（競合になる）」。
     *
     * この表がそのまま Phase 10 の検証結果になる（docs/phase10-combinations.md）。
     */
    public static function strategies(): array
    {
        return [
            'なし' => [BatchStrategy::None, [
                '場面1' => 'confirmed',   // キャンセルが確定で上書きされる
                '場面2' => 'pending',     // 確定が利用者の古いフォームで戻る
                '場面3' => 'cancelled',   // 確定を知らずにキャンセルする
                '場面4' => 'confirmed',   // 自動キャンセルが確定で上書きされる
                '場面5' => 'confirmed',
            ]],
            '状態ガード' => [BatchStrategy::StateGuard, [
                '場面1' => 'cancelled',
                '場面2' => 'pending',     // version を上げないので利用者が気づけない
                '場面3' => 'cancelled',
                '場面4' => 'cancelled',
                '場面5' => 'confirmed',
            ]],
            '状態ガード + version' => [BatchStrategy::StateGuardWithVersion, [
                '場面1' => 'cancelled',
                '場面2' => self::OK,
                '場面3' => self::OK,
                '場面4' => 'cancelled',
                '場面5' => 'confirmed',
            ]],
            '楽観的ロック' => [BatchStrategy::Optimistic, [
                '場面1' => 'cancelled',
                '場面2' => self::OK,
                '場面3' => self::OK,
                '場面4' => 'cancelled',
                '場面5' => 'pending',     // 顧客名の変更でも version が変わるのでスキップする
            ]],
            '悲観的ロック' => [BatchStrategy::Pessimistic, [
                '場面1' => 'cancelled',
                '場面2' => self::OK,
                '場面3' => self::OK,
                '場面4' => 'cancelled',
                '場面5' => 'confirmed',
            ]],
        ];
    }

    /**
     * 補足: 編集ロック方式の利用者とバッチの組み合わせ
     *
     * 推奨の書き方（状態ガード + version）のバッチでも、利用者側の保存が version を確認しない
     * 編集ロック方式（Phase 7）だと、利用者の古いフォームでバッチの確定が戻ってしまう。
     * バッチは編集ロックを確認しないので、ロック中でも予約を変更できるため。
     */
    #[Test]
    public function 弱点_編集ロック中の予約をバッチが確定するとロック保持者の保存で戻ってしまう(): void
    {
        $this->useLockMode(LockMode::EditLock);
        $reservation = $this->createReservation();

        $form = $this->openEdit($reservation, 'A');                        // A が編集ロックを取る
        $this->runConfirmBatch(BatchStrategy::StateGuardWithVersion, $reservation);
        $this->assertSame('confirmed', $reservation->refresh()->status->value, 'バッチは編集ロックを無視して確定する');

        $this->save($reservation, $form, ['number_of_people' => 4], 'A')
            ->assertRedirect(route('reservations.show', $reservation));     // 保存が通る

        $this->assertSame('pending', $reservation->refresh()->status->value, 'バッチの確定が A の古いフォームで戻る');
    }

    #[Test]
    public function 状態遷移のルールに反するバッチは実行できない(): void
    {
        $this->artisan('reservations:change-status', ['from' => 'cancelled', 'to' => 'confirmed'])
            ->expectsOutputToContain('状態遷移のルールで許可されていません')
            ->assertExitCode(2);
    }

    private function runConfirmBatch(BatchStrategy $strategy, Reservation $reservation, ?\Closure $afterRead = null)
    {
        return app(ReservationStatusBatch::class)->run(
            ReservationStatus::Pending, ReservationStatus::Confirmed, $strategy, [$reservation->id], $afterRead,
        );
    }
}
