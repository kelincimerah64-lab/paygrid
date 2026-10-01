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
            'cards' => MerchantTicketService::CARDS,
            'categoriesByDepartment' => [
                'cs' => MerchantTicketService::CS_CATEGORIES,
                'tech' => MerchantTicketService::TECH_CATEGORIES,
                'finance' => MerchantTicketService::FINANCE_CATEGORIES,
            ],
            'categoryMeta' => MerchantTicketService::CATEGORY_META,
        ]);
    }

    public function store(Request $request, CsScopeResolver $scope, MerchantTicketService $tickets, AuditLogService $audit): RedirectResponse
    {
        $user = $request->user();

        $merchantIds = $user->role === 'superadmin'
            ? Merchant::query()->where('general_ticket_enabled', true)->pluck('id')
            : $scope->merchantIds($user);

        $cardKey = $request->input('card');
        $cardDef = MerchantTicketService::CARDS[$cardKey] ?? null;
        $department = $cardDef['department'] ?? null;
        $categoryKeys = $department ? array_keys($tickets->categoriesFor($department)) : [];
        $categoryKey = (string) $request->input('category');

        $validated = $request->validate(array_merge([
            'merchant_id' => ['required', Rule::in($merchantIds)],
            'card' => ['required', Rule::in(array_keys(MerchantTicketService::CARDS))],
            'category' => ['required', Rule::in($categoryKeys)],
            'description' => ['required', 'string', 'max:500'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['mimes:jpg,jpeg,png,pdf,mp4', 'max:10240'],
        ], $tickets->fieldRules($categoryKey)));

        $data = [
            'department' => $department,
            'category' => $validated['category'],
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'],
            'metadata' => $tickets->metadataFrom($categoryKey, $validated),
        ];

        $merchant = Merchant::query()->where('general_ticket_enabled', true)->findOrFail($validated['merchant_id']);

        $ticket = $tickets->create($merchant, $user, $data, $request->file('attachments', []));
        $audit->record('merchant_ticket.created', $ticket, null, $ticket->only(['merchant_id', 'department', 'category', 'ticket_no']));

        return redirect()->route('merchant.tickets.show', [$merchant, $ticket])->with('status', 'Tiket berhasil dibuat: '.$ticket->ticket_no);
    }
}
