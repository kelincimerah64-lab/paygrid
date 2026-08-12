<?php

namespace App\Services\Gateway;

use App\Models\Agent;

class HilogateMerchantGroupService
{
    public function createGroupPayload(Agent $agent): array
    {
        return [
            'name' => $agent->name,
            'code' => $agent->code,
            'email' => $agent->email,
            'contact' => $agent->contact,
        ];
    }
}
