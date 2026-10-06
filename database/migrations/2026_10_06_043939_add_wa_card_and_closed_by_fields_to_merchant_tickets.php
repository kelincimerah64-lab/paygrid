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
            $table->dropColumn('wa_reminder_sent_at');
            $table->foreignId('closed_by_user_id')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->string('wa_active_message_id')->nullable()->after('closed_by_user_id');
            $table->string('wa_active_chat_id')->nullable()->after('wa_active_message_id');
            $table->unsignedTinyInteger('wa_reminder_stage')->default(0)->after('wa_active_chat_id');
            $table->timestamp('wa_reminder_stage_at')->nullable()->after('wa_reminder_stage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->dropColumn(['wa_active_message_id', 'wa_active_chat_id', 'wa_reminder_stage', 'wa_reminder_stage_at']);
            $table->dropConstrainedForeignId('closed_by_user_id');
            $table->timestamp('wa_reminder_sent_at')->nullable();
        });
    }
};
