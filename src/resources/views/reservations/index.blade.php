@extends('layouts.app')

@section('title', '予約一覧')

@section('content')
    <div class="actions" style="justify-content: space-between; margin-bottom: 16px;">
        <h1 style="margin: 0;">予約一覧</h1>
        <a href="{{ route('reservations.create') }}" class="btn btn-primary">新規予約</a>
    </div>

    <div class="card table-wrap" style="padding: 0;">
        <table>
            <thead>
            <tr>
                <th class="num">ID</th>
                <th>顧客名</th>
                <th class="num">人数</th>
                <th>予約日時</th>
                <th>ステータス</th>
                {{-- 排他制御の検証中に値の変化を一覧でも追えるよう表示している --}}
                <th class="num">version</th>
                <th>編集ロック</th>
                <th>updated_at</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($reservations as $reservation)
                <tr>
                    <td class="num">{{ $reservation->id }}</td>
                    <td>{{ $reservation->customer_name }}</td>
                    <td class="num">{{ $reservation->number_of_people }}名</td>
                    <td>{{ $reservation->reservation_date->format('Y-m-d H:i') }}</td>
                    <td>@include('reservations._status_badge', ['status' => $reservation->status])</td>
                    <td class="num mono">{{ $reservation->version }}</td>
                    <td>@include('reservations._lock_badge')</td>
                    <td class="mono">{{ $reservation->updated_at->format('Y-m-d H:i:s') }}</td>
                    <td class="actions">
                        <a href="{{ route('reservations.show', $reservation) }}">詳細</a>
                        <a href="{{ route('reservations.edit', $reservation) }}">編集</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="muted">予約はありません。</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{--
        Laravel 標準のページャー表示は Tailwind か Bootstrap 前提の HTML なので、
        CSS フレームワークを使わないこのアプリでは前後リンクだけを自前で出す。
    --}}
    @if ($reservations->hasPages())
        <div class="actions">
            @if ($reservations->previousPageUrl())
                <a href="{{ $reservations->previousPageUrl() }}" class="btn">前へ</a>
            @endif
            <span class="muted">{{ $reservations->currentPage() }} / {{ $reservations->lastPage() }} ページ</span>
            @if ($reservations->nextPageUrl())
                <a href="{{ $reservations->nextPageUrl() }}" class="btn">次へ</a>
            @endif
        </div>
    @endif
@endsection
