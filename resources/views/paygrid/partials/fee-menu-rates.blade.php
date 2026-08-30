@php
    $__feeRateOptions = $feeMenus->optionsFor($role, $typeCategory ?? null);
    $__feeRateInputName = $inputName ?? 'fee_menu_rates';
    $__feeRateCurrent = $currentRates ?? [];
    $__feeRateFormId = $formId ?? null;
@endphp
<table class="table qris-table fee-rate-table">
    <thead><tr><th>Menu</th><th>Floor</th><th>Fee (%)</th></tr></thead>
    <tbody>
    @foreach($__feeRateOptions as $__feeRateKey => $__feeRateOption)
        <tr>
            <td>{{ $__feeRateOption['label'] }}</td>
            <td>{{ $__feeRateOption['floor'] }}%</td>
            <td>
                <input type="text" inputmode="decimal" autocomplete="off"
                    @if($__feeRateFormId) form="{{ $__feeRateFormId }}" @endif
                    name="{{ $__feeRateInputName }}[{{ $__feeRateKey }}]"
                    value="{{ old($__feeRateInputName.'.'.$__feeRateKey, $__feeRateCurrent[$__feeRateKey] ?? '') }}"
                    data-floor="{{ $__feeRateOption['floor'] }}"
                    placeholder="0">
                <small class="field-hint warn" hidden>min {{ $__feeRateOption['floor'] }}%</small>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
