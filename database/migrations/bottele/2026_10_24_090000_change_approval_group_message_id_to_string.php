<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Holds either a Telegram message_id or a WAHA poll id ("true_…@g.us_…"), so it cannot stay numeric.
        Schema::connection('bottele')->table('bot_tickets', function (Blueprint $table) {
            $table->string('approval_group_message_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('bottele')->table('bot_tickets', function (Blueprint $table) {
            $table->bigInteger('approval_group_message_id')->nullable()->change();
        });
    }
};
