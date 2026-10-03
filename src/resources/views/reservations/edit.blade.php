@extends('layouts.app')

@section('title', '予約編集')

@section('content')
    <h1>予約編集 #{{ $reservation->id }}</h1>

    {{-- hidden 項目（編集開始時の値）の検証エラー。フォームの入力欄が無いのでここにまとめて出す --}}
    @error('original_updated_at') <div class="flash flash-error">{{ $message }}</div> @enderror

    {{--
        編集画面を開いた時点の値。
        楽観的ロックでは、この値を hidden で送り返して「開いた後に誰かが更新していないか」を確認する。
    --}}
    <p class="muted mono">
        編集開始時点: version = {{ $reservation->version }} /
        updated_at = {{ $reservation->updated_at->format('Y-m-d H:i:s') }}
    </p>

    {{--
        競合時の表示。
        フォームには old() で「あなたの入力」が残っている。ここには DB の最新の値を出して見比べられるようにする。
        hidden の original_updated_at は最新の値に更新されているので、
        内容を確認してもう一度保存すると、今度は最新の状態に対する更新として受け付けられる。
    --}}
    @if (session('conflict'))
        <div class="card" style="border-color: #fca5a5;">
            <h2 class="section-title">他の人の更新後の最新内容</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>項目</th><th>最新の内容（DB）</th><th>あなたの入力</th></tr>
                    </thead>
                    <tbody>
                    @php
                        $rows = [
                            '顧客名' => [$reservation->customer_name, old('customer_name')],
                            '人数' => [$reservation->number_of_people, old('number_of_people')],
                            '予約日時' => [$reservation->reservation_date->format('Y-m-d\TH:i'), old('reservation_date')],
                            'ステータス' => [$reservation->status->value, old('status')],
                        ];
                    @endphp
                    @foreach ($rows as $label => [$latest, $input])
                        <tr @if ((string) $latest !== (string) $input) style="background: #fef2f2;" @endif>
                            <td>{{ $label }}</td>
                            <td class="mono">{{ $latest }}</td>
                            <td class="mono">{{ $input }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted" style="margin-bottom: 0;">色の付いた行は、最新の内容とあなたの入力が異なる項目です。</p>
        </div>
    @endif

    {{-- HTML のフォームは GET/POST しか送れないので、@method('PUT') で PUT として扱わせる --}}
    <form method="POST" action="{{ route('reservations.update', $reservation) }}" class="card">
        @csrf
        @method('PUT')
        {{--
            編集画面を開いた時点の排他制御方式。
            保存時に現在の方式と比べ、途中で切り替えられていたら更新を止める。
        --}}
        <input type="hidden" name="lock_mode" value="{{ $lockMode->value }}">

        @if ($lockMode === \App\Enums\LockMode::UpdatedAt)
            {{--
                updated_at 方式: 編集画面を開いた時点の updated_at を送り返す。
                old() を使わず必ず DB の値を出すのがポイント。
                old() を使うと、競合で戻ってきたときに「古い updated_at」が復元され、何度保存しても競合し続ける。
            --}}
            <input type="hidden" name="original_updated_at" value="{{ $reservation->updated_at->format('Y-m-d H:i:s') }}">
        @endif
        <p class="muted" style="margin-top: 0;">この画面は「{{ $lockMode->label() }}」で開きました。保存時もこの方式で処理されます。</p>
        @include('reservations._form')

        <div class="actions">
            <button type="submit" class="btn btn-primary">更新する</button>
            <a href="{{ route('reservations.show', $reservation) }}" class="btn">キャンセル</a>
        </div>
    </form>
@endsection
