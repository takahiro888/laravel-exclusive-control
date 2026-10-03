<?php

namespace App\Http\Controllers;

use App\Enums\LockMode;
use App\Services\LockModeSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 排他制御方式の切り替え
 */
class LockModeController extends Controller
{
    public function update(Request $request, LockModeSetting $setting): RedirectResponse
    {
        // 実装済みの方式だけを受け付ける（未実装の方式を直接 POST されても切り替えない）
        $implemented = array_map(
            fn (LockMode $mode) => $mode->value,
            array_filter(LockMode::cases(), fn (LockMode $mode) => $mode->implemented()),
        );

        $validated = $request->validate([
            'lock_mode' => ['required', Rule::in($implemented)],
        ], [
            'lock_mode.in' => 'その排他制御方式はまだ実装されていません。',
        ]);

        $mode = LockMode::from($validated['lock_mode']);
        $setting->change($mode);

        return back()->with('success', "排他制御方式を「{$mode->label()}」に切り替えました。");
    }
}
