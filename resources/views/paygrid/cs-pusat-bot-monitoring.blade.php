@extends('layouts.paygrid')

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">CS Pusat</div>
        <h1>Monitoring Bot Telegram</h1>
    </div>
</div>

@include('paygrid.partials.bot-monitoring-panel', ['botRouteName' => 'center-support.bot-monitoring'])
@endsection
