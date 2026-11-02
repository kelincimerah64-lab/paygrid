<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The bot's own copies of the merchant's screenshot, so they can follow the ticket card:
        // re-sent with every reminder in the tech group and deleted when the ticket is rejected.
        Schema::connection('bottele')->table('bot_tickets', function (Blueprint $table) {
            $table->bigInteger('tech_photo_message_id')->nullable()->after('cs_group_message_id');
            $table->bigInteger('merchant_photo_message_id')->nullable()->after('merchant_message_id');
        });
    }

    public function down(): void
    {
        Schema::connection('bottele')->table('bot_tickets', function (Blueprint $table) {
            $table->dropColumn(['tech_photo_message_id', 'merchant_photo_message_id']);
        });
    }
};
