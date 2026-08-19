<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('topup_requests', 'cs_note')) {
            return;
        }

        Schema::table('topup_requests', function (Blueprint $table) {
            $table->text('cs_note')->nullable()->after('gateway_payload');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('topup_requests', 'cs_note')) {
            return;
        }

        Schema::table('topup_requests', function (Blueprint $table) {
            $table->dropColumn('cs_note');
        });
    }
};
