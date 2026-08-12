<?php

namespace App\Http\Controllers;

use App\Models\GatewaySyncLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class MonitoringController extends Controller
{
    public function index(): View
    {
        $base = GatewaySyncLog::query()
            ->when(request('gateway'), fn ($query, $gateway) => $query->where('gateway', $gateway))
            ->when(request('status'), fn ($query, $status) => $query->where('status', $status))
            ->when(request('from'), fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when(request('to'), fn ($query, $to) => $query->whereDate('created_at', '<=', $to));

        $logs = (clone $base)->with('merchant:id,slug,name')->latest()->paginate(config('paygrid.reports.default_page_size', 50))->withQueryString();

        return view('paygrid.monitoring', [
            'logs' => $logs,
            'successCount' => (clone $base)->where('status', 'success')->count(),
            'failedCount' => (clone $base)->where('status', 'failed')->count(),
            'queuedJobs' => DB::table('jobs')->count(),
            'failedJobs' => DB::table('failed_jobs')->count(),
        ]);
    }
}
