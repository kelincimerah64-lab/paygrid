<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Services\CsScopeResolver;
use App\Services\Navigation\MenuBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaTicketController extends Controller
{
    public function index(Request $request, CsScopeResolver $scope, MenuBuilder $menus): View
    {
        $user = $request->user();
        $isAgent = $user->role === 'agent';

        $merchants = Merchant::query()
            ->with('agent')
            ->with(['merchantTickets' => fn ($query) => $query->whereIn('status', ['open', 'in_progress'])->latest()])
            ->when($user->role !== 'superadmin', fn ($query) => $query->whereIn('id', $scope->merchantIds($user)))
            ->where('general_ticket_enabled', true)
            ->orderBy('name')
            ->get();

        return view('paygrid.ma-tickets', [
            'roleLabel' => $isAgent ? ($scope->label($user)) : 'MA',
            'menus' => $isAgent ? $menus->agent() : $menus->ma(),
            'active' => 'create-ticket',
            'merchants' => $merchants,
        ]);
    }
}
