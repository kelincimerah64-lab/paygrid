<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Links a web IP Whitelist ticket to its Telegram support bot ticket (bottele.bot_tickets.ticket_id).
        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->string('bot_ticket_id')->nullable()->index()->after('internal_note');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->dropColumn('bot_ticket_id');
        });
    }
};
