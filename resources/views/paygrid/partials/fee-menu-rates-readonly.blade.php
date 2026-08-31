@php
    $__feeRoOptions = $feeMenus->optionsFor($role, $typeCategory ?? null);
    $__feeRoRates = $rates ?? [];
    $__feeRoShowFloor = $role === 'ma';
@endphp
<div class="fee-rate-grid">
    @foreach($__feeRoOptions as $__feeRoKey => $__feeRoOption)
        @php $__feeRoValue = (float) ($__feeRoRates[$__feeRoKey] ?? 0); @endphp
        <div class="fee-rate-row">
            <span class="fee-rate-label" title="{{ $__feeRoOption['label'] }}">{{ $__feeRoOption['label'] }}@if($__feeRoShowFloor)<small> (min {{ $__feeRoOption['floor'] }}%)</small>@endif</span>
            <strong>{{ $__feeRoValue > 0 ? number_format($__feeRoValue, 2, ',', '.').'%' : '-' }}</strong>
        </div>
    @endforeach
</div>
