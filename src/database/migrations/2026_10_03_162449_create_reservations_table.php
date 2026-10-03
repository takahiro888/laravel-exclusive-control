<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            // ---------------------------------------------------------------
            // 業務データ（予約の中身）
            // ---------------------------------------------------------------
            $table->string('customer_name', 100);
            $table->unsignedSmallInteger('number_of_people');
            $table->dateTime('reservation_date');

            // MySQL の ENUM 型ではなく文字列で持つ。
            // ENUM は値を追加するたびに ALTER TABLE が必要になるため、
            // 取りうる値の管理は PHP 側の Enum (App\Enums\ReservationStatus) に任せる。
            $table->string('status', 20);

            // ---------------------------------------------------------------
            // 排他制御用のカラム（Phase 2 時点では未使用）
            // ---------------------------------------------------------------

            // Phase 5: version 方式の楽観的ロックで使う。
            // 更新のたびに +1 し、「編集画面を開いたときの version」と一致する場合だけ更新を許す。
            // updated_at と違い、同じ秒に2回更新されても必ず値が変わるのが利点。
            $table->unsignedInteger('version')->default(1);

            // Phase 7: 編集ロック方式で使う。
            // locked_by    : 誰が編集中か（このアプリはログイン機能を持たないので操作者名の文字列）
            // locked_at    : いつ編集を開始したか（ロック取得日時）
            // locked_until : いつまでロックが有効か（有効期限付き編集ロックで使う）
            // ロックされていない状態を表すため、すべて NULL を許可する。
            $table->string('locked_by', 50)->nullable();
            $table->dateTime('locked_at')->nullable();
            $table->dateTime('locked_until')->nullable();

            // Phase 4: updated_at 方式の楽観的ロックで使う。
            // Laravel の timestamps() は TIMESTAMP 型で「秒」精度。
            // つまり同じ秒の中で2回更新されると updated_at が変わらず、競合を見逃す可能性がある。
            // これは updated_at 方式の弱点として Phase 4 で検証するため、あえてデフォルトのままにしている。
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
