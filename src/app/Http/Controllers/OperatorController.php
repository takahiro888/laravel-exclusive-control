<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureOperator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 操作者名の変更（検証で「A」「B」のように分かりやすい名前にするため）
 */
class OperatorController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // locked_by カラムの長さ（50）に合わせる
            'operator_name' => ['required', 'string', 'max:50'],
        ], [
            'operator_name.*' => '操作者名は50文字以内で入力してください。',
        ]);

        $request->session()->put(EnsureOperator::SESSION_KEY, $validated['operator_name']);

        return back()->with('success', "操作者名を「{$validated['operator_name']}」に変更しました。");
    }
}
