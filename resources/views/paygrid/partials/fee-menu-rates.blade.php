@php
    $__feeRateOptions = $feeMenus->optionsFor($role, $typeCategory ?? null);
    $__feeRateInputName = $inputName ?? 'fee_menu_rates';
    $__feeRateCurrent = $currentRates ?? [];
    $__feeRateFormId = $formId ?? null;
    $__feeRateShowFloor = $role === 'ma';
    $__feeRateGrouped = ($style ?? 'flat') === 'grouped';
@endphp
@if($__feeRateGrouped)
    @php
        $__feeRateGroups = [];
        $__feeRateSubtypes = [];
        foreach ($__feeRateOptions as $__feeRateKey => $__feeRateOption) {
            $__feeRateParts = explode(' ', $__feeRateOption['label'], 2);
            $__feeRateCategory = $__feeRateParts[0];
            $__feeRateSub = $__feeRateParts[1] ?? $__feeRateParts[0];
            $__feeRateGroups[$__feeRateCategory][$__feeRateSub] = [
                'key' => $__feeRateKey,
                'option' => $__feeRateOption,
            ];
            if (! in_array($__feeRateSub, $__feeRateSubtypes, true)) {
                $__feeRateSubtypes[] = $__feeRateSub;
            }
        }
    @endphp
    <div class="fee-rate-grouped">
        @foreach($__feeRateGroups as $__feeRateCategory => $__feeRateCells)
            <div class="fee-rate-group-row">
                <div class="fee-rate-group-label">{{ $__feeRateCategory }}</div>
                <div class="fee-rate-group-cells" style="grid-template-columns:repeat({{ count($__feeRateSubtypes) }}, minmax(78px, 1fr))">
                    @foreach($__feeRateSubtypes as $__feeRateSub)
                        @if(isset($__feeRateCells[$__feeRateSub]))
                            @php($__feeRateCell = $__feeRateCells[$__feeRateSub])
                            <div class="fee-rate-cell">
                                <span class="fee-rate-sub-label" title="{{ $__feeRateCell['option']['label'] }}">{{ $__feeRateSub }}@if($__feeRateShowFloor)<small> (min {{ $__feeRateCell['option']['floor'] ?? 0 }}%)</small>@endif</span>
                                <span class="fee-rate-input-wrap">
                                    <input type="text" inputmode="decimal" autocomplete="off" class="fee-rate-input"
                                        @if($__feeRateFormId) form="{{ $__feeRateFormId }}" @endif
                                        name="{{ $__feeRateInputName }}[{{ $__feeRateCell['key'] }}]"
                                        value="{{ old($__feeRateInputName.'.'.$__feeRateCell['key'], $__feeRateCurrent[$__feeRateCell['key']] ?? '') }}"
                                        @if($__feeRateShowFloor) data-floor="{{ $__feeRateCell['option']['floor'] ?? 0 }}" @endif
                                        placeholder="0">
                                    <span class="fee-rate-suffix">%</span>
                                </span>
                                @if($__feeRateShowFloor)
                                    <small class="field-hint warn" hidden>min {{ $__feeRateCell['option']['floor'] ?? 0 }}%</small>
                                @endif
                            </div>
                        @else
                            <div class="fee-rate-cell fee-rate-cell-empty" aria-hidden="true">
                                <span class="fee-rate-sub-label">{{ $__feeRateSub }}</span>
                                <span class="fee-rate-input-wrap"><input type="text" class="fee-rate-input" disabled></span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@else
    <div class="fee-rate-grid">
        @foreach($__feeRateOptions as $__feeRateKey => $__feeRateOption)
            <div class="fee-rate-row">
                <span class="fee-rate-label" title="{{ $__feeRateOption['label'] }}">{{ $__feeRateOption['label'] }}@if($__feeRateShowFloor)<small> (min {{ $__feeRateOption['floor'] ?? 0 }}%)</small>@endif</span>
                <input type="text" inputmode="decimal" autocomplete="off" class="fee-rate-input"
                    @if($__feeRateFormId) form="{{ $__feeRateFormId }}" @endif
                    name="{{ $__feeRateInputName }}[{{ $__feeRateKey }}]"
                    value="{{ old($__feeRateInputName.'.'.$__feeRateKey, $__feeRateCurrent[$__feeRateKey] ?? '') }}"
                    @if($__feeRateShowFloor) data-floor="{{ $__feeRateOption['floor'] ?? 0 }}" @endif
                    placeholder="0">
                @if($__feeRateShowFloor)
                    <small class="field-hint warn" hidden>min {{ $__feeRateOption['floor'] ?? 0 }}%</small>
                @endif
            </div>
        @endforeach
    </div>
@endif
