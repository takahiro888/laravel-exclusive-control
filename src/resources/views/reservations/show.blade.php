@extends('layouts.app')

@section('title', '予約詳細')

@section('content')
    <h1>予約詳細 #{{ $reservation->id }}</h1>

    <div class="card">
        <h2 class="section-title">予約内容</h2>
        <dl class="details">
            <dt>顧客名</dt><dd>{{ $reservation->customer_name }}</dd>
            <dt>人数</dt><dd>{{ $reservation->number_of_people }}名</dd>
            <dt>予約日時</dt><dd>{{ $reservation->reservation_date->format('Y-m-d H:i') }}</dd>
            <dt>ステータス</dt><dd>@include('reservations._status_badge', ['status' => $reservation->status])</dd>
        </dl>
    </div>

    {{--
        編集ロックの状態。ロックがかかっていれば強制解除ボタンを出す。
        有効期限なしの編集ロックは、編集画面を開いたままブラウザを閉じられると永久に残るため、
        このような救済手段が必要になる（実務では管理者だけが使える機能にする）。
    --}}
    @if ($reservation->locked_by)
        <div class="card" style="border-color: #fdba74;">
            <h2 class="section-title">編集ロック</h2>
            <p style="margin-top: 0;">
                @include('reservations._lock_badge')
                さんが {{ $reservation->locked_at?->format('H:i:s') }} から編集中です。
                @if ($reservation->locked_until)
                    有効期限: {{ $reservation->locked_until->format('H:i:s') }}
                    {{ $reservation->isLockExpired() ? '（期限切れのため、他の人が編集を開始できます）' : '' }}
                @else
                    有効期限はありません（解放されるまで他の人は編集できません）。
                @endif
            </p>
            <form method="POST" action="{{ route('reservations.edit-lock.force', $reservation) }}"
                  onsubmit="return confirm('{{ $reservation->locked_by }} さんの編集ロックを強制解除しますか？');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn">強制解除</button>
            </form>
        </div>
    @endif

    {{--
        排他制御用カラムの現在値。
        どの方式でどのカラムが変化するのか（しないのか）を確認するために表示している。
    --}}
    <div class="card">
        <h2 class="section-title">排他制御用の値</h2>
        <dl class="details mono">
            <dt>version</dt><dd>{{ $reservation->version }}</dd>
            <dt>updated_at</dt><dd>{{ $reservation->updated_at->format('Y-m-d H:i:s') }}</dd>
            <dt>locked_by</dt><dd>{{ $reservation->locked_by ?? 'NULL' }}</dd>
            <dt>locked_at</dt><dd>{{ $reservation->locked_at?->format('Y-m-d H:i:s') ?? 'NULL' }}</dd>
            <dt>locked_until</dt><dd>{{ $reservation->locked_until?->format('Y-m-d H:i:s') ?? 'NULL' }}</dd>
            <dt>created_at</dt><dd>{{ $reservation->created_at->format('Y-m-d H:i:s') }}</dd>
        </dl>
    </div>

    {{--
        キャンセル操作（Phase 10）。
        画面を開いた時点の version を送り、その間に他の人やバッチが更新していたら競合にする。
        画面上部の「排他制御方式」とは関係なく、常に楽観的ロックで処理する。
    --}}
    @if ($reservation->status->canTransitionTo(\App\Enums\ReservationStatus::Cancelled))
        <form method="POST" action="{{ route('reservations.cancel', $reservation) }}" class="card"
              onsubmit="return confirm('この予約をキャンセルしますか？');">
            @csrf
            <input type="hidden" name="original_version" value="{{ $reservation->version }}">
            <h2 class="section-title">予約のキャンセル</h2>
            <p class="muted" style="margin-top: 0;">
                画面を開いた時点（version = {{ $reservation->version }}）から変更されていない場合だけキャンセルします（楽観的ロック）。
            </p>
            <button type="submit" class="btn btn-danger">この予約をキャンセルする</button>
        </form>
    @endif

    <div class="actions">
        <a href="{{ route('reservations.edit', $reservation) }}" class="btn btn-primary">編集</a>
        <a href="{{ route('reservations.index') }}" class="btn">一覧に戻る</a>
        <form method="POST" action="{{ route('reservations.destroy', $reservation) }}"
              onsubmit="return confirm('この予約を削除しますか？');" style="margin-left: auto;">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">削除</button>
        </form>
    </div>
@endsection
