<?php

namespace Tests\Concerns;

use App\Enums\LockMode;
use App\Enums\ReservationStatus;
use App\Http\Middleware\EnsureOperator;
use App\Models\Reservation;
use App\Services\LockModeSetting;
use Illuminate\Testing\TestResponse;

/**
 * 「利用者 A が編集画面を開く」「B が保存する」といった操作をテストで書くための補助。
 *
 * ブラウザでの操作を次のように再現する:
 *   1. openEdit()  : 編集画面を GET し、フォームに入っている値（入力欄 + hidden）を受け取る
 *   2. 値を書き換える（利用者が入力する）
 *   3. save()      : そのフォームの値を PUT する
 *
 * hidden の値（original_version など）は、実際に描画された HTML から取り出している。
 * テストで DB の値を直接使うと、「編集画面が hidden を正しく出力しているか」を検証できないため。
 */
trait InteractsWithReservations
{
    protected function useLockMode(LockMode $mode): void
    {
        app(LockModeSetting::class)->change($mode);
    }

    /**
     * 操作者を切り替える（ブラウザ A / B の代わり）。
     * テストではセッションが1つなので、リクエストのたびに操作者名を上書きする。
     */
    protected function asOperator(string $operator): static
    {
        return $this->withSession([EnsureOperator::SESSION_KEY => $operator]);
    }

    /**
     * 編集画面を開き、ブラウザのフォームが送るはずの値を返す。
     *
     * @return array<string, mixed>
     */
    protected function openEdit(Reservation $reservation, string $operator = 'A'): array
    {
        $response = $this->asOperator($operator)->get(route('reservations.edit', $reservation));
        $response->assertOk();

        $html = $response->getContent();

        // 入力欄の値（編集画面を開いた時点の DB の値）
        $form = [
            'customer_name' => $this->inputValue($html, 'customer_name'),
            'number_of_people' => $this->inputValue($html, 'number_of_people'),
            'reservation_date' => $this->inputValue($html, 'reservation_date'),
            'status' => $this->selectedValue($html, 'status'),
        ];

        // hidden の値（lock_mode / original_updated_at / original_version など）
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $name, $value]) {
            if (! in_array($name, ['_token', '_method'], true)) {
                $form[$name] = html_entity_decode($value);
            }
        }

        // 悲観的ロックの検証用オプション: ブラウザの初期状態（0秒、version 確認あり）を再現する。
        // verify_version は hidden の 0 の後にチェックボックスの 1 が送られるので、1 で上書きする。
        if (($form['lock_mode'] ?? null) === LockMode::Pessimistic->value) {
            $form['hold_seconds'] = '0';
            $form['verify_version'] = '1';
        }

        return $form;
    }

    /**
     * 編集画面のフォームの値に、利用者の変更を加えて保存する。
     *
     * @param  array<string, mixed>  $form  openEdit() の戻り値
     * @param  array<string, mixed>  $changes  利用者が書き換えた項目
     */
    protected function save(Reservation $reservation, array $form, array $changes = [], string $operator = 'A'): TestResponse
    {
        return $this->asOperator($operator)
            ->put(route('reservations.update', $reservation), [...$form, ...$changes]);
    }

    /**
     * 検証用の予約（顧客名や人数は固定にして、どの変更が残ったかを分かりやすくする）
     */
    protected function createReservation(array $attributes = []): Reservation
    {
        // refresh(): version など DB の初期値で入るカラムは、create() 直後のモデルには入っていないので読み直す
        return Reservation::factory()->create([
            'customer_name' => '山田 太郎',
            'number_of_people' => 2,
            'reservation_date' => '2026-12-24 19:00:00',
            'status' => ReservationStatus::Pending,
            ...$attributes,
        ])->refresh();
    }

    private function inputValue(string $html, string $name): ?string
    {
        // 属性が複数行に分かれているので、name から value までを改行込みで探す
        return preg_match('/name="'.$name.'"[^>]*?value="([^"]*)"/s', $html, $m)
            ? html_entity_decode($m[1])
            : null;
    }

    private function selectedValue(string $html, string $name): ?string
    {
        if (! preg_match('/<select[^>]*name="'.$name.'".*?<\/select>/s', $html, $select)) {
            return null;
        }

        return preg_match('/<option value="([^"]*)"\s+selected/s', $select[0], $m) ? $m[1] : null;
    }
}
