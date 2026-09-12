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

        $merchants = Merchant::query()
            ->with('agent')
            ->when($user->role !== 'superadmin', fn ($query) => $query->whereIn('id', $scope->merchantIds($user)))
            ->where('general_ticket_enabled', true)
            ->orderBy('name')
            ->get();

        return view('paygrid.ma-tickets', [
            'roleLabel' => 'MA',
            'menus' => $menus->ma(),
            'active' => 'create-ticket',
            'merchants' => $merchants,
        ]);
    }
}
