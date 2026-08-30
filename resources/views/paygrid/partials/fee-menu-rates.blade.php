@php
    $__feeRateOptions = $feeMenus->optionsFor($role, $typeCategory ?? null);
    $__feeRateInputName = $inputName ?? 'fee_menu_rates';
    $__feeRateCurrent = $currentRates ?? [];
    $__feeRateFormId = $formId ?? null;
    $__feeRateShowFloor = $role === 'ma';
@endphp
<div class="fee-rate-grid">
    @foreach($__feeRateOptions as $__feeRateKey => $__feeRateOption)
        <div class="fee-rate-row">
            <span class="fee-rate-label" title="{{ $__feeRateOption['label'] }}">{{ $__feeRateOption['label'] }}@if($__feeRateShowFloor)<small> (min {{ $__feeRateOption['floor'] }}%)</small>@endif</span>
            <input type="text" inputmode="decimal" autocomplete="off" class="fee-rate-input"
                @if($__feeRateFormId) form="{{ $__feeRateFormId }}" @endif
                name="{{ $__feeRateInputName }}[{{ $__feeRateKey }}]"
                value="{{ old($__feeRateInputName.'.'.$__feeRateKey, $__feeRateCurrent[$__feeRateKey] ?? '') }}"
                @if($__feeRateShowFloor) data-floor="{{ $__feeRateOption['floor'] }}" @endif
                placeholder="0">
            @if($__feeRateShowFloor)
                <small class="field-hint warn" hidden>min {{ $__feeRateOption['floor'] }}%</small>
            @endif
        </div>
    @endforeach
</div>
