<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topup_requests', function (Blueprint $table) {
            $table->timestamp('succeeded_at')->nullable()->index()->after('submitted_at');
        });

        DB::table('topup_requests')
            ->where('status', 'success')
            ->whereNull('succeeded_at')
            ->whereNotNull('submitted_at')
            ->update(['succeeded_at' => DB::raw('submitted_at')]);
    }

    public function down(): void
    {
        Schema::table('topup_requests', function (Blueprint $table) {
            $table->dropColumn('succeeded_at');
        });
    }
};
