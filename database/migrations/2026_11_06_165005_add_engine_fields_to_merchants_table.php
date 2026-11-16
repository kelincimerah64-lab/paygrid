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
            $table->string('engine_base_url')->nullable()->after('engine_type');
            $table->text('engine_token_api')->nullable()->after('engine_base_url');
            $table->string('engine_agent_back_panel')->nullable()->after('engine_token_api');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['engine_base_url', 'engine_token_api', 'engine_agent_back_panel']);
        });
    }
};
