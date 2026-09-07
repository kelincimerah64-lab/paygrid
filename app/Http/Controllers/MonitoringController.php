<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\GatewaySyncLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class MonitoringController extends Controller
{
    public function logs(): View
    {
        $search = trim((string) request('q', ''));
        $action = (string) request('action', '');
        $base = AuditLog::query()
            ->when($action !== '', fn ($query) => $query->where('action', $action))
            ->when(request('from'), fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when(request('to'), fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('action', 'like', "%{$search}%")
                    ->orWhere('target_type', 'like', "%{$search}%")
                    ->orWhereRelation('actor', 'email', 'like', "%{$search}%");
            }));

        $logs = (clone $base)->with('actor')->latest('created_at')->paginate(config('paygrid.reports.default_page_size', 50))->withQueryString();

        return view('paygrid.admin-logs', [
            'logs' => $logs,
            'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

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
