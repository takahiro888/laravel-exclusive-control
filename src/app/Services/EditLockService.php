<?php

namespace App\Services;

use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * 編集ロックの取得・解放
 *
 * 楽観的ロック・悲観的ロックとの違い:
 *   これまでの方式は「保存の瞬間」に競合を確認していた。
 *   編集ロックは「編集画面を開いた瞬間」にロックを取り、他の人をそもそも編集画面に入れない。
 *   ロックは DB のロック機能ではなく、reservations テーブルの locked_by / locked_at / locked_until
 *   という普通のカラムに「誰が編集中か」を書き込むことで表現する（アプリケーションレベルのロック）。
 *   そのため、リクエストや DB 接続をまたいでロックを保持できる。
 *
 * 共通のポイント:
 *   1. 「ロックされているか確認してから書き込む」と、確認と書き込みの間に他の人が割り込める（Check-Then-Act）。
 *      条件付きの UPDATE 1文で「空いていれば取る」を行い、更新件数で取れたかどうかを判定する。
 *   2. ロックの取得・解放は業務データの変更ではないので、updated_at も version も変えない。
 *      （updated_at が変わると、updated_at 方式の楽観的ロックや「最終更新日時」の表示がずれる）
 *   3. 期限の計算と比較は MySQL の NOW() で行う。Web サーバーが複数台ある場合、
 *      サーバーごとの時計のずれで判定が変わらないよう、時計を DB の1つに揃える。
 */
class EditLockService
{
    /**
     * 編集ロックを取得する。取得できたら true。
     *
     * 有効期限なし:
     *   update reservations
     *      set locked_by = 'A', locked_at = NOW(), locked_until = NULL
     *    where id = 1
     *      and (locked_by is null or locked_by = 'A')
     *
     * 有効期限あり:
     *   update reservations
     *      set locked_by = 'A', locked_at = NOW(), locked_until = NOW() + INTERVAL 60 SECOND
     *    where id = 1
     *      and (locked_by is null or locked_by = 'A' or locked_until < NOW())   ← 期限切れなら奪える
     *
     * 自分がすでにロックを持っている場合（編集画面の再読み込みなど）も取得できる。
     * 有効期限ありの場合は、そのたびに期限が延長される。
     */
    public function acquire(Reservation $reservation, string $operator, bool $withExpiry): bool
    {
        $lockedUntil = $withExpiry
            // TTL は設定ファイルの整数なので SQL に直接埋め込んでも安全（int にキャストしている）
            ? DB::raw('NOW() + INTERVAL '.$this->ttlSeconds().' SECOND')
            : null;

        $affected = Reservation::query()
            ->whereKey($reservation->getKey())
            ->where(function ($query) use ($operator, $withExpiry) {
                $query->whereNull('locked_by')
                    ->orWhere('locked_by', $operator);

                if ($withExpiry) {
                    $query->orWhere('locked_until', '<', DB::raw('NOW()'));
                }
            })
            // toBase(): Eloquent の Builder::update() は updated_at を自動で SET に加えてしまう。
            // ロック操作で updated_at を変えないよう、素のクエリビルダーで UPDATE する。
            ->toBase()
            ->update([
                'locked_by' => $operator,
                'locked_at' => DB::raw('NOW()'),
                'locked_until' => $lockedUntil,
            ]);

        // ATTR_FOUND_ROWS を有効にしているので「WHERE に一致した件数」が返る。
        // 自分のロックを同じ秒に取り直して値が変わらなかった場合も 1 になる。
        return $affected === 1;
    }

    /**
     * 自分の編集ロックを解放する（キャンセル時）。
     *
     *   update reservations set locked_by = NULL, locked_at = NULL, locked_until = NULL
     *    where id = 1 and locked_by = 'A'
     *
     * locked_by = 自分 を条件にしているので、期限切れの後に他の人が取ったロックを誤って外すことはない。
     */
    public function release(Reservation $reservation, string $operator): bool
    {
        return Reservation::query()
            ->whereKey($reservation->getKey())
            ->where('locked_by', $operator)
            ->toBase()
            ->update(self::unlockedColumns()) === 1;
    }

    /**
     * 誰のロックでも強制的に解放する。
     *
     * 有効期限なしの編集ロックは、編集画面を開いたままブラウザを閉じられると永久に残る。
     * 実務では管理者だけが使える機能にするが、検証用アプリなので誰でも使える。
     */
    public function forceRelease(Reservation $reservation): void
    {
        Reservation::query()
            ->whereKey($reservation->getKey())
            ->toBase()
            ->update(self::unlockedColumns());
    }

    public function ttlSeconds(): int
    {
        return max(1, (int) config('exclusive_control.edit_lock_ttl_seconds'));
    }

    /**
     * ロックを外した状態のカラム値（保存時の UPDATE でも使う）
     *
     * @return array<string, null>
     */
    public static function unlockedColumns(): array
    {
        return ['locked_by' => null, 'locked_at' => null, 'locked_until' => null];
    }
}
