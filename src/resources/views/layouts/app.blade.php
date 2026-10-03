<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '予約管理') | {{ config('app.name') }}</title>
    {{--
        排他制御の検証が目的なので、Vite / npm のビルドは使わず CSS をここに直接書いている。
        npm install しなくても画面が表示できる。
    --}}
    <style>
        :root {
            --bg: #f5f6f8; --surface: #fff; --text: #1f2933; --muted: #6b7280; --border: #d9dde3;
            --primary: #2563eb; --danger: #dc2626; --success-bg: #dcfce7; --success-text: #166534;
            --error-bg: #fee2e2; --error-text: #991b1b;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Hiragino Sans", sans-serif; background: var(--bg); color: var(--text); line-height: 1.6; }
        header { background: var(--surface); border-bottom: 1px solid var(--border); }
        .header-inner, main { max-width: 1040px; margin: 0 auto; padding: 0 16px; }
        .header-inner { display: flex; align-items: center; justify-content: space-between; gap: 16px; min-height: 56px; flex-wrap: wrap; }
        .brand { font-weight: 700; color: var(--text); text-decoration: none; }
        main { padding-top: 24px; padding-bottom: 48px; }
        h1 { font-size: 1.4rem; margin: 0 0 16px; }
        a { color: var(--primary); }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 16px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; background: var(--surface); }
        th, td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; white-space: nowrap; }
        th { font-size: .85rem; color: var(--muted); font-weight: 600; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .mono { font-family: ui-monospace, Menlo, monospace; font-size: .85rem; }
        .muted { color: var(--muted); }
        .btn { display: inline-block; padding: 7px 14px; border-radius: 6px; border: 1px solid var(--border); background: var(--surface); color: var(--text); text-decoration: none; font-size: .9rem; cursor: pointer; }
        .btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
        .btn-danger { background: var(--danger); border-color: var(--danger); color: #fff; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .flash { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
        .flash-success { background: var(--success-bg); color: var(--success-text); }
        .flash-error { background: var(--error-bg); color: var(--error-text); }
        .badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: .8rem; border: 1px solid var(--border); }
        .badge-pending { background: #fef9c3; border-color: #fde047; }
        .badge-confirmed { background: #dcfce7; border-color: #86efac; }
        .badge-cancelled { background: #f3f4f6; color: var(--muted); }
        .mode-form { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: .9rem; }
        .mode-form select { padding: 5px 8px; border: 1px solid var(--border); border-radius: 6px; font-size: .9rem; max-width: 100%; }
        .mode-banner { border-left: 4px solid var(--mode-color); background: var(--surface); padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
        .mode-banner strong { color: var(--mode-color); }
        .mode-none { --mode-color: #dc2626; }
        .mode-updated_at, .mode-version { --mode-color: #2563eb; }
        .mode-pessimistic { --mode-color: #7c3aed; }
        .mode-edit_lock, .mode-edit_lock_expiry { --mode-color: #d97706; }
        dl.details { display: grid; grid-template-columns: 160px 1fr; gap: 8px 16px; margin: 0; }
        dl.details dt { color: var(--muted); }
        dl.details dd { margin: 0; }
        .section-title { font-size: 1rem; margin: 0 0 12px; }
        .form-row { margin-bottom: 14px; }
        .form-row label { display: block; font-weight: 600; margin-bottom: 4px; }
        .form-row input, .form-row select { width: 100%; max-width: 360px; padding: 7px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 1rem; }
        .field-error { color: var(--error-text); font-size: .85rem; margin-top: 2px; }
        @media (max-width: 600px) { dl.details { grid-template-columns: 1fr; } dl.details dt { margin-top: 8px; } }
    </style>
</head>
<body>
<header>
    <div class="header-inner">
        <a href="{{ route('reservations.index') }}" class="brand">{{ config('app.name') }}</a>
        {{--
            排他制御方式の切り替え。アプリ全体で1つの設定なので、
            ここで切り替えると他のブラウザ・タブの操作にも反映される。
            未実装の方式は disabled にして、実装予定の Phase を表示する。
        --}}
        <form method="POST" action="{{ route('lock-mode.update') }}" class="mode-form">
            @csrf
            @method('PUT')
            <label for="lock_mode_switch">排他制御方式</label>
            <select id="lock_mode_switch" name="lock_mode">
                @foreach ($lockModes as $mode)
                    <option value="{{ $mode->value }}"
                        @selected($mode === $currentLockMode)
                        @disabled(! $mode->implemented())>
                        {{ $mode->label() }}{{ $mode->implemented() ? '' : "（Phase {$mode->phase()}）" }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn">切り替え</button>
        </form>
    </div>
</header>
<main>
    {{-- 現在どの方式で操作しているかを全画面の上部に表示する --}}
    <div class="mode-banner mode-{{ $currentLockMode->value }}">
        現在の排他制御方式: <strong>{{ $currentLockMode->label() }}</strong>
        <div class="muted" style="font-size: .85rem;">{{ $currentLockMode->description() }}</div>
    </div>

    @if (session('success'))
        <div class="flash flash-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="flash flash-error">{{ session('error') }}</div>
    @endif

    @error('lock_mode') <div class="flash flash-error">{{ $message }}</div> @enderror

    @yield('content')
</main>
</body>
</html>
