<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mirrors the "session" sheet: one conversation state per (user_id, chat_id).
        Schema::connection('bottele')->create('bot_sessions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->bigInteger('chat_id');
            $table->bigInteger('support_command_message_id')->nullable();
            $table->bigInteger('support_menu_message_id')->nullable();
            $table->bigInteger('support_prompt_message_id')->nullable();
            $table->bigInteger('user_detail_message_id')->nullable();
            $table->string('state', 60)->nullable();
            $table->string('category')->nullable();
            $table->string('temp_ticket_id')->nullable();
            $table->bigInteger('resolve_chat_id')->nullable();
            $table->bigInteger('resolve_message_id')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['user_id', 'chat_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('bottele')->dropIfExists('bot_sessions');
    }
};
