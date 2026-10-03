<?php

namespace Database\Seeders;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Database\Seeder;

class ReservationSeeder extends Seeder
{
    public function run(): void
    {
        // 検証で毎回同じデータを使えるよう、ID=1 は固定の内容にしておく。
        // Phase 3 以降の「2つのブラウザで同じ予約を編集する」手順ではこの予約を使う。
        Reservation::factory()->create([
            'customer_name' => '山田 太郎',
            'number_of_people' => 2,
            'reservation_date' => now()->addDays(7)->setTime(19, 0),
            'status' => ReservationStatus::Pending,
        ]);

        Reservation::factory()->count(9)->create();
    }
}
