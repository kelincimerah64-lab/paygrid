@php
    $__feeRateOptions = $feeMenus->optionsFor($role, $typeCategory ?? null);
    $__feeRateInputName = $inputName ?? 'fee_menu_rates';
    $__feeRateCurrent = $currentRates ?? [];
    $__feeRateFormId = $formId ?? null;
@endphp
<div class="fee-rate-grid">
    @foreach($__feeRateOptions as $__feeRateKey => $__feeRateOption)
        <div class="fee-rate-row">
            <span class="fee-rate-label" title="{{ $__feeRateOption['label'] }}">{{ $__feeRateOption['label'] }}</span>
            <input type="text" inputmode="decimal" autocomplete="off" class="fee-rate-input"
                @if($__feeRateFormId) form="{{ $__feeRateFormId }}" @endif
                name="{{ $__feeRateInputName }}[{{ $__feeRateKey }}]"
                value="{{ old($__feeRateInputName.'.'.$__feeRateKey, $__feeRateCurrent[$__feeRateKey] ?? '') }}"
                placeholder="0">
        </div>
    @endforeach
</div>
