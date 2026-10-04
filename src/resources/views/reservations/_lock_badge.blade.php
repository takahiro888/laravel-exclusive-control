{{-- 編集ロックの状態表示。期限切れのロックは「他の人が奪える」状態なので見た目を変える --}}
@if ($reservation->locked_by)
    <span class="lock-badge {{ $reservation->isLockExpired() ? 'expired' : '' }}"
          title="{{ $reservation->isLockExpired() ? '有効期限切れ' : '編集中' }}">
        {{ $reservation->locked_by }}
    </span>
    @if ($reservation->isLockExpired())
        <span class="muted" style="font-size: .8rem;">期限切れ</span>
    @endif
@else
    -
@endif
