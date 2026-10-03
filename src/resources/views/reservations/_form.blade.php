{{--
    新規作成と編集で共通のフォーム項目。
    old() を優先するのは、バリデーションエラーで戻ってきたときに入力内容を残すため。
--}}
<div class="form-row">
    <label for="customer_name">顧客名</label>
    <input type="text" id="customer_name" name="customer_name" maxlength="100"
           value="{{ old('customer_name', $reservation->customer_name) }}" required>
    @error('customer_name') <div class="field-error">{{ $message }}</div> @enderror
</div>

<div class="form-row">
    <label for="number_of_people">人数</label>
    <input type="number" id="number_of_people" name="number_of_people" min="1" max="50"
           value="{{ old('number_of_people', $reservation->number_of_people) }}" required>
    @error('number_of_people') <div class="field-error">{{ $message }}</div> @enderror
</div>

<div class="form-row">
    <label for="reservation_date">予約日時</label>
    {{-- datetime-local は "2026-10-10T19:00" 形式の値を要求する --}}
    <input type="datetime-local" id="reservation_date" name="reservation_date"
           value="{{ old('reservation_date', $reservation->reservation_date?->format('Y-m-d\TH:i')) }}" required>
    @error('reservation_date') <div class="field-error">{{ $message }}</div> @enderror
</div>

<div class="form-row">
    <label for="status">ステータス</label>
    <select id="status" name="status" required>
        @foreach ($statuses as $status)
            <option value="{{ $status->value }}"
                @selected(old('status', $reservation->status?->value) === $status->value)>
                {{ $status->label() }}
            </option>
        @endforeach
    </select>
    @error('status') <div class="field-error">{{ $message }}</div> @enderror
</div>
