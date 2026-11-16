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
        Schema::table('agent_onboarding_links', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
        });
        Schema::table('agent_onboarding_links', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_id')->nullable()->change();
        });
        Schema::table('agent_onboarding_links', function (Blueprint $table) {
            $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_onboarding_links', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
        });
        Schema::table('agent_onboarding_links', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_id')->nullable(false)->change();
        });
        Schema::table('agent_onboarding_links', function (Blueprint $table) {
            $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
        });
    }
};
