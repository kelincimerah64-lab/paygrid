<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Services\AuditLogService;
use App\Services\CsScopeResolver;
use App\Services\MerchantTicketService;
use App\Services\Navigation\MenuBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MaTicketController extends Controller
{
    public function index(Request $request, CsScopeResolver $scope, MenuBuilder $menus): View
    {
        $user = $request->user();
        $isAgent = $user->role === 'agent';

        $merchants = Merchant::query()
            ->with('agent')
            ->with(['merchantTickets' => fn ($query) => $query->whereIn('status', ['open', 'in_progress'])->latest('last_message_at')])
            ->when($user->role !== 'superadmin', fn ($query) => $query->whereIn('id', $scope->merchantIds($user)))
            ->where('general_ticket_enabled', true)
            ->orderBy('name')
            ->get();

        $groupedMerchants = $merchants->groupBy(fn (Merchant $merchant) => $merchant->agent?->name ?: 'Lainnya')
            ->map(fn ($group) => $group->map(fn (Merchant $merchant) => ['id' => $merchant->id, 'name' => $merchant->name])->values())
            ->sortKeys();

        return view('paygrid.ma-tickets', [
            'roleLabel' => $isAgent ? ($scope->label($user)) : 'MA',
            'menus' => $isAgent ? $menus->agent() : $menus->ma(),
            'active' => 'create-ticket',
            'merchants' => $merchants,
            'groupedMerchants' => $groupedMerchants,
            'csCategories' => MerchantTicketService::CS_CATEGORIES,
            'techCategories' => MerchantTicketService::TECH_CATEGORIES,
            'financeCategories' => MerchantTicketService::FINANCE_CATEGORIES,
        ]);
    }

    public function store(Request $request, CsScopeResolver $scope, MerchantTicketService $tickets, AuditLogService $audit): RedirectResponse
    {
        $user = $request->user();

        $merchantIds = $user->role === 'superadmin'
            ? Merchant::query()->where('general_ticket_enabled', true)->pluck('id')
            : $scope->merchantIds($user);

        $data = $request->validate([
            'merchant_id' => ['required', Rule::in($merchantIds)],
            'department' => ['required', Rule::in(MerchantTicketService::DEPARTMENTS)],
            'category' => ['required'],
            'title' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['mimes:jpg,jpeg,png,pdf,mp4', 'max:10240'],
        ]);

        $categoryKeys = array_keys($tickets->categoriesFor($data['department']));
        if (! in_array($data['category'], $categoryKeys, true)) {
            return back()->withErrors(['category' => 'Menu tidak sesuai dengan tujuan yang dipilih.'])->withInput();
        }

        $merchant = Merchant::query()->where('general_ticket_enabled', true)->findOrFail($data['merchant_id']);

        $ticket = $tickets->create($merchant, $user, $data, $request->file('attachments', []));
        $audit->record('merchant_ticket.created', $ticket, null, $ticket->only(['merchant_id', 'department', 'category', 'ticket_no']));

        return redirect()->route('merchant.tickets.show', [$merchant, $ticket])->with('status', 'Tiket berhasil dibuat: '.$ticket->ticket_no);
    }
}
