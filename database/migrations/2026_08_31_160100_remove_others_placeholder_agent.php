<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $agent = DB::table('agents')->where('code', 'AG-OTHER')->orWhere('email', 'others@paygrid.local')->first();

        if (! $agent) {
            return;
        }

        DB::table('merchants')->where('agent_id', $agent->id)->update(['agent_id' => null, 'updated_at' => now()]);

        DB::table('users')->where('role', 'agent')
            ->where(fn ($query) => $query->where('username', $agent->code)->orWhere('email', $agent->email))
            ->delete();

        DB::table('agents')->where('id', $agent->id)->delete();
    }

    public function down(): void
    {
        // Placeholder agent removal is not reversible - matches the
        // established pattern of prior one-way data-cleanup migrations.
    }
};
