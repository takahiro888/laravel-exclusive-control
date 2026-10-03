<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    /** @use HasFactory<\Database\Factories\ReservationFactory> */
    use HasFactory;

    /**
     * フォームから一括代入（fill / create / update）できるカラム。
     *
     * version と locked_* は意図的に含めていない。
     * これらを含めると、悪意のあるリクエストで version=999 のように送られたとき
     * 楽観的ロックのチェックを回避されたり、他人の編集ロックを解除されたりする恐れがある。
     * 排他制御用のカラムは、必ずサーバー側の処理だけが書き換えるようにする。
     */
    protected $fillable = [
        'customer_name',
        'number_of_people',
        'reservation_date',
        'status',
    ];

    /**
     * DB の値を PHP の型に変換する設定。
     * locked_until は Phase 7 で「現在時刻と比較」するため Carbon にしておく。
     */
    protected function casts(): array
    {
        return [
            'number_of_people' => 'integer',
            'reservation_date' => 'datetime',
            'status' => ReservationStatus::class,
            'version' => 'integer',
            'locked_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }
}
