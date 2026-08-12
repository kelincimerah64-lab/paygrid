@extends('layouts.paygrid', ['roleLabel' => $merchant?->name ?? 'Top Up', 'menus' => [], 'active' => ''])

@section('content')
<section class="card pad" style="max-width:900px; margin:80px auto">
    <div style="text-align:center">
        <h1>Top Up</h1>
        <div class="sub">Masukkan UserID member dan nominal deposit.</div>
    </div>

    @if(!$merchant || !$merchant->topup_enabled)
        <div class="card pad section" style="background:#fffaf0; border-color:#ffe0a4">
            Toko ini tidak memakai link topup PayGrid. Silakan hubungi admin toko.
        </div>
    @else
        @php($minimumAmount = $merchant->minimumTopupAmount())
        @if($errors->any())<div class="card pad section" style="background:#fff1f0; border-color:#f0b4ae; color:#a6221b">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('topup.submit', $merchant) }}" class="form-grid section">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
            <label>User ID<input name="customer_reference" value="{{ old('customer_reference') }}" required placeholder="User ID member"></label>
            <label>Nominal<input name="amount" inputmode="numeric" type="number" min="{{ $minimumAmount }}" max="{{ config('paygrid.topup.maximum_amount') }}" value="{{ old('amount') }}" required placeholder="Minimal Rp {{ number_format($minimumAmount, 0, ',', '.') }}"></label>
            <button class="btn primary" style="grid-column:1 / -1">Submit Top Up</button>
        </form>
    @endif
</section>
@endsection
