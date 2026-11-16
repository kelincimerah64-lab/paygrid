@extends('layouts.paygrid')

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">CS Pusat</div>
        <h1>Monitoring Bot Telegram</h1>
    </div>
</div>

@if(session('status'))<div class="status-banner"><span class="badge ok">{{ session('status') }}</span></div>@endif
@if($errors->any())<div class="status-banner"><span class="badge danger">{{ $errors->first() }}</span></div>@endif

@include('paygrid.partials.bot-monitoring-panel', ['botRouteName' => 'center-support.bot-monitoring', 'botExportRouteName' => 'center-support.bot-monitoring.export', 'botRejectRouteName' => ($botCanReject ?? false) ? 'center-support.bot-monitoring.reject' : null])
@endsection
