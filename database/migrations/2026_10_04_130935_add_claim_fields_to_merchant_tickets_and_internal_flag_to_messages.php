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
        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->foreignId('claimed_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable()->after('claimed_by_user_id');
            $table->timestamp('wa_reminder_sent_at')->nullable()->after('claimed_at');
        });

        Schema::table('merchant_ticket_messages', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('is_staff');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('claimed_by_user_id');
            $table->dropColumn(['claimed_at', 'wa_reminder_sent_at']);
        });

        Schema::table('merchant_ticket_messages', function (Blueprint $table) {
            $table->dropColumn('is_internal');
        });
    }
};
