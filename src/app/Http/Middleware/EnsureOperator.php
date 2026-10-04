<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 操作者名をセッションに用意する。
 *
 * 編集ロックでは「誰がロックを持っているか」を locked_by に記録する必要がある。
 * このアプリはログイン機能を持たないので、ブラウザのセッションごとに操作者名を持たせる。
 * 未設定なら「利用者-1234」のような仮の名前を付け、ヘッダーから変更できるようにしている。
 *
 * セッションはブラウザ（Cookie）単位なので、同じブラウザの別タブは同じ操作者になる。
 * A と B を検証するときは、別のブラウザか、通常ウィンドウとプライベートウィンドウを使う。
 */
class EnsureOperator
{
    public const SESSION_KEY = 'operator_name';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has(self::SESSION_KEY)) {
            $request->session()->put(self::SESSION_KEY, '利用者-'.random_int(1000, 9999));
        }

        return $next($request);
    }
}
