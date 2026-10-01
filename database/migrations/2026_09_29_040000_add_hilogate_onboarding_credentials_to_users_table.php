<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('hilogate_onboarding_email')->nullable()->after('fee_menu_rates');
            $table->text('hilogate_onboarding_password')->nullable()->after('hilogate_onboarding_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['hilogate_onboarding_email', 'hilogate_onboarding_password']);
        });
    }
};
