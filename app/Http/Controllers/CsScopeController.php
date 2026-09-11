<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\SupportTicket;
use App\Models\TopupRequest;
use App\Services\CsScopeResolver;
use App\Services\Navigation\MenuBuilder;
use Illuminate\View\View;

class CsScopeController extends Controller
{
    public function index(MenuBuilder $menus, CsScopeResolver $resolver): View
    {
        $user = request()->user();
        $merchantIds = $resolver->merchantIds($user);
        $search = trim((string) request('q', ''));
        $merchantFilter = (int) request('merchant_id', 0);

        $tickets = SupportTicket::query()
            ->with(['merchant', 'topupRequest'])
            ->whereIn('merchant_id', $merchantIds)
            ->where('status', '!=', 'done')
            ->when($merchantFilter, fn ($query) => $query->where('merchant_id', $merchantFilter))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('ticket_no', 'like', "{$search}%")
                    ->orWhere('reference', 'like', "{$search}%")
                    ->orWhere('client_reference', 'like', "{$search}%")
                    ->orWhereRelation('merchant', 'name', 'like', "{$search}%");
            }))
            ->latest()
            ->paginate(config('paygrid.reports.default_page_size', 50), ['*'], 'tickets_page')
            ->withQueryString();

        $problemTopups = TopupRequest::query()
            ->with('merchant')
            ->whereIn('merchant_id', $merchantIds)
            ->whereDoesntHave('ticket')
            ->whereIn('status', ['pending', 'expired', 'failed', 'rejected'])
            ->when($merchantFilter, fn ($query) => $query->where('merchant_id', $merchantFilter))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('payment_id', 'like', "{$search}%")
                    ->orWhere('rrn', 'like', "{$search}%")
                    ->orWhere('transaction_id', 'like', "{$search}%")
                    ->orWhereRelation('merchant', 'name', 'like', "{$search}%");
            }))
            ->latest('submitted_at')
            ->paginate(config('paygrid.reports.default_page_size', 50), ['*'], 'topups_page')
            ->withQueryString();

        $menu = match ($user->role) {
            'ma' => $menus->ma(),
            'agent' => $menus->agent(),
            default => $menus->csScope(),
        };

        return view('paygrid.cs-scope', [
            'roleLabel' => $resolver->label($user),
            'menus' => $menu,
            'active' => 'monitor',
            'tickets' => $tickets,
            'problemTopups' => $problemTopups,
            'merchants' => Merchant::query()->whereIn('id', $merchantIds)->orderBy('name')->get(['id', 'name']),
            'search' => $search,
            'merchantFilter' => $merchantFilter,
        ]);
    }
}
