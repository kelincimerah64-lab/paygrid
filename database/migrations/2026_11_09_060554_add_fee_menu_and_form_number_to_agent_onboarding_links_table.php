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
            $table->string('fee_menu')->nullable()->after('kind');
            $table->json('fee_menu_rates')->nullable()->after('fee_menu');
            $table->string('form_number')->nullable()->unique()->after('token');
            $table->foreignId('created_by_user_id')->nullable()->after('agent_id')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_onboarding_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn(['fee_menu', 'fee_menu_rates', 'form_number']);
        });
    }
};
