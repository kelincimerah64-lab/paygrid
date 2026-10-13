<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_onboarding_links', function (Blueprint $table) {
            $table->boolean('reusable')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('agent_onboarding_links', function (Blueprint $table) {
            $table->dropColumn('reusable');
        });
    }
};
