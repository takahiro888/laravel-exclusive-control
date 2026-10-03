<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Reservation>
 */
class ReservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            // APP_FAKER_LOCALE=ja_JP なので日本人の名前が生成される
            'customer_name' => fake()->name(),
            'number_of_people' => fake()->numberBetween(1, 8),
            // 予約は30分単位にしておく（画面の datetime-local 入力と相性が良い）
            'reservation_date' => fake()->dateTimeBetween('+1 day', '+30 days')
                ->setTime(fake()->numberBetween(11, 21), fake()->randomElement([0, 30])),
            'status' => fake()->randomElement(ReservationStatus::cases()),
            // version / locked_* はマイグレーションのデフォルト値（1 / NULL）に任せる
        ];
    }
}
