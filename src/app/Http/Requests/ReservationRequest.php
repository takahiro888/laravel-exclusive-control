<?php

namespace App\Http\Requests;

use App\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 予約の新規作成・更新で共通に使う入力チェック。
 *
 * ここでは業務データ（customer_name など）だけを検証する。
 * Phase 4 以降で追加する「編集開始時の updated_at / version」などの
 * 排他制御用の値は、方式ごとに別途扱う。
 */
class ReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // ログイン機能を持たない検証用アプリなので、誰でも操作できる
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:100'],
            'number_of_people' => ['required', 'integer', 'min:1', 'max:50'],
            'reservation_date' => ['required', 'date'],
            // Rule::enum() はルールオブジェクトのため messages() の独自メッセージが効かない。
            // 日本語メッセージを出すため、Enum の値一覧から in ルールを組み立てている。
            'status' => ['required', Rule::in(array_column(ReservationStatus::cases(), 'value'))],
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_name' => '顧客名',
            'number_of_people' => '人数',
            'reservation_date' => '予約日時',
            'status' => 'ステータス',
        ];
    }

    /**
     * Laravel 本体には日本語の翻訳ファイルが含まれていないため、
     * 使っているルールのメッセージだけここで日本語にしている。
     */
    public function messages(): array
    {
        return [
            'required' => ':attributeは必須です。',
            'string' => ':attributeは文字列で入力してください。',
            'max' => ':attributeは:max以下で入力してください。',
            'min' => ':attributeは:min以上で入力してください。',
            'integer' => ':attributeは整数で入力してください。',
            'date' => ':attributeは正しい日時で入力してください。',
            'in' => ':attributeの値が不正です。',
        ];
    }
}
