<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\User;

class CsScopeResolver
{
    public function merchantIds(User $user): array
    {
        if ($user->role === 'cs_ma') {
            return Merchant::query()->whereRelation('agent', 'ma_user_id', $user->ma_user_id)->pluck('id')->all();
        }

        if ($user->role === 'cs_agent') {
            return Merchant::query()->where('agent_id', $user->agent_id)->pluck('id')->all();
        }

        return [];
    }

    public function canAccessMerchant(User $user, Merchant $merchant): bool
    {
        return in_array($merchant->id, $this->merchantIds($user), true);
    }

    public function label(User $user): string
    {
        if ($user->role === 'cs_ma') {
            return 'CS MA — '.(User::find($user->ma_user_id)?->name ?? '-');
        }

        if ($user->role === 'cs_agent') {
            return 'CS Agent — '.(Agent::find($user->agent_id)?->name ?? '-');
        }

        return 'CS';
    }
}
