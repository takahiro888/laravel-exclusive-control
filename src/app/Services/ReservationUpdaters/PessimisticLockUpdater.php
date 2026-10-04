<?php

namespace App\Services\ReservationUpdaters;

use App\Exceptions\ReservationConflictException;
use App\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * 悲観的ロック（SELECT ... FOR UPDATE）
 *
 * 考え方:
 *   「競合は起きるもの」と悲観的に考え、更新する前に行ロックを取って他の人を待たせる。
 *   ロックはトランザクションがコミット（またはロールバック）されるまで保持される。
 *
 * 排他制御のタイミング:
 *   保存リクエストのトランザクションの中だけ。
 *   SELECT ... FOR UPDATE を実行した瞬間からコミットまでの間、他のトランザクションの
 *   「SELECT ... FOR UPDATE」「UPDATE」「DELETE」は同じ行に対して待たされる。
 *   ※ 通常の SELECT（ロックを取らない読み取り）は待たされない（MVCC で過去のコミット済みの値を読む）。
 *
 * 発行される SQL:
 *   begin
 *   select * from reservations where id = 1 limit 1 for update   ← ここで行ロックを取得（取れなければ待つ）
 *   update reservations set number_of_people = 4, version = 3, updated_at = '...' where id = 1
 *   commit                                                       ← ここでロック解放
 *
 * 競合した場合:
 *   - 同時に保存した場合: 後から来た方が FOR UPDATE で待たされ、先の方のコミット後に最新の行を読む。
 *   - 待ち時間が innodb_lock_wait_timeout（このプロジェクトでは10秒）を超えると、MySQL がエラー 1205 を返す。
 *
 * 重要な限界:
 *   ロックは「保存リクエストの中」でしか効かない。編集画面を開くリクエストと保存のリクエストは別の
 *   DB 接続なので、「画面を開いてから保存するまで」に他の人が更新したことは、ロックだけでは分からない。
 *   そのため、ロックを取った後に version を確認している（verify_version を外すと確認しない）。
 */
class PessimisticLockUpdater implements ReservationUpdater
{
    /** MySQL の「ロック待ちタイムアウト」のエラー番号 */
    private const ER_LOCK_WAIT_TIMEOUT = 1205;

    public function update(Reservation $reservation, array $attributes, array $context = []): Reservation
    {
        $context = Validator::make($context, [
            'original_version' => ['required', 'integer', 'min:1'],
            // 以下2つは検証用オプション（実際のアプリには不要）
            'hold_seconds' => ['nullable', 'integer', 'in:0,5,15'],
            'verify_version' => ['nullable', 'boolean'],
        ], [
            'original_version.*' => '編集開始時のバージョンが不正です。編集画面を開き直してください。',
        ])->validate();

        $holdSeconds = (int) ($context['hold_seconds'] ?? 0);
        $verifyVersion = (bool) ($context['verify_version'] ?? true);

        try {
            // DB::transaction() はクロージャの前後で BEGIN / COMMIT を発行し、例外時は ROLLBACK する。
            // FOR UPDATE のロックはトランザクションの終わりまで保持されるので、トランザクションが必須。
            // （トランザクションの外で FOR UPDATE を実行すると、自動コミットで即座にロックが外れ意味がない）
            return DB::transaction(function () use ($reservation, $attributes, $context, $holdSeconds, $verifyVersion) {
                // -----------------------------------------------------------------
                // 行ロックの取得
                //
                // $reservation（ルートモデルバインディングで取得済み）をそのまま使わず、読み直している。
                // $reservation はロックを取る前の通常の SELECT で読んだ値なので、
                // ロック待ちをしている間に他の人が更新していれば、もう古い値になっているため。
                // FOR UPDATE は分離レベル REPEATABLE READ でも「最新のコミット済みの行」を読む（ロッキングリード）。
                // -----------------------------------------------------------------
                $locked = Reservation::query()
                    ->whereKey($reservation->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // 検証用: ロックを持ったまま待つ。この間に別のタブで保存すると、そちらが待たされる様子を観察できる
                if ($holdSeconds > 0) {
                    sleep($holdSeconds);
                }

                // -----------------------------------------------------------------
                // 「画面を開いてから」の変更の確認
                //
                // Phase 4 では「PHP の if で確認してから UPDATE するのは危険（Check-Then-Act）」と書いた。
                // ここでは安全。FOR UPDATE で行ロックを持っているので、確認してから UPDATE するまでの間に
                // 他のトランザクションがこの行を更新することはできないため。
                // -----------------------------------------------------------------
                if ($verifyVersion && $locked->version !== (int) $context['original_version']) {
                    throw new ReservationConflictException(
                        'この予約は、あなたが編集画面を開いた後に他の人が更新しました。最新の内容を確認してから、もう一度保存してください。'
                    );
                }

                $locked->fill($attributes);
                // ロックを持っているので、PHP で +1 した値を書き込んでも他の更新と衝突しない
                // （Phase 5 では SQL の式 version + 1 と WHERE version = ? で同じことを保証していた）
                $locked->version = $locked->version + 1;
                $locked->save();

                return $locked;
            });
        } catch (QueryException $e) {
            // ロック待ちが innodb_lock_wait_timeout を超えた場合。
            // エラー画面にせず、利用者に「他の人が保存中だった」と伝える。
            if (($e->errorInfo[1] ?? null) === self::ER_LOCK_WAIT_TIMEOUT) {
                throw new ReservationConflictException(
                    '他の人がこの予約を保存処理中のため、待ちきれずに中断しました（ロック待ちタイムアウト）。少し待ってから、もう一度保存してください。'
                );
            }

            throw $e;
        }
    }
}
