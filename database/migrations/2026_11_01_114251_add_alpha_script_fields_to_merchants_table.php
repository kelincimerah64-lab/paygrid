<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('callback_alpha_url')->nullable()->after('va_callback_url');
            $table->json('ip_pg')->nullable()->after('api_ip_whitelist');
            $table->json('ip_alpha')->nullable()->after('ip_pg');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['callback_alpha_url', 'ip_pg', 'ip_alpha']);
        });
    }
};
