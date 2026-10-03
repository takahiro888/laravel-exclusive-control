<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 排他制御で競合を検知したときに投げる例外。
 *
 * Phase 3 の「排他制御なし」では競合を検知しないので、まだ投げられることはない。
 * Phase 4 以降の各方式は、競合時にこの例外を投げ、コントローラが受け取って
 * 「他の人が先に更新しました」のようなメッセージを画面に出す。
 */
class ReservationConflictException extends RuntimeException
{
}
