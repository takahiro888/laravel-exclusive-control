@extends('layouts.app')

@section('title', '予約編集')

@section('content')
    <h1>予約編集 #{{ $reservation->id }}</h1>

    {{--
        編集画面を開いた時点の値。
        Phase 4 以降は、この値を hidden で送り返して「開いた後に誰かが更新していないか」を確認する。
    --}}
    <p class="muted mono">
        編集開始時点: version = {{ $reservation->version }} /
        updated_at = {{ $reservation->updated_at->format('Y-m-d H:i:s') }}
    </p>

    {{-- HTML のフォームは GET/POST しか送れないので、@method('PUT') で PUT として扱わせる --}}
    <form method="POST" action="{{ route('reservations.update', $reservation) }}" class="card">
        @csrf
        @method('PUT')
        @include('reservations._form')

        <div class="actions">
            <button type="submit" class="btn btn-primary">更新する</button>
            <a href="{{ route('reservations.show', $reservation) }}" class="btn">キャンセル</a>
        </div>
    </form>
@endsection
