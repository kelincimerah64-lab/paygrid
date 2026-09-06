<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $codes = [
            'AG-CM', 'AG-CM-NX', 'AG-CM-OCTO', 'AG-EPC-CM', 'AG-EPC-OCTO',
            'AG-GROUP-A', 'AG-MA', 'AG-MICHAEL', 'AG-NNP-DEMO-GROUP',
            'AG-NXGRUP', 'AG-OTHERS', 'AGN-136262', 'AGN-250581',
        ];

        $agents = DB::table('agents')->whereIn('code', $codes)->get();

        foreach ($agents as $agent) {
            if (DB::table('merchants')->where('agent_id', $agent->id)->exists()) {
                continue;
            }

            DB::table('users')->where('role', 'agent')
                ->where(fn ($query) => $query->where('username', $agent->code)->orWhere('email', $agent->email))
                ->delete();

            DB::table('agents')->where('id', $agent->id)->delete();
        }
    }

    public function down(): void
    {
        // Legacy-agent cleanup is not reversible - matches the established
        // pattern of prior one-way data-cleanup migrations.
    }
};
