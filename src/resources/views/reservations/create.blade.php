@extends('layouts.app')

@section('title', '新規予約')

@section('content')
    <h1>新規予約</h1>

    <form method="POST" action="{{ route('reservations.store') }}" class="card">
        @csrf
        @include('reservations._form')

        <div class="actions">
            <button type="submit" class="btn btn-primary">登録する</button>
            <a href="{{ route('reservations.index') }}" class="btn">キャンセル</a>
        </div>
    </form>
@endsection
