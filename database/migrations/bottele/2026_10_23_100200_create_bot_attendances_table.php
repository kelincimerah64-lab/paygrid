<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mirrors the "attendance" sheet: one row per technician.
        Schema::connection('bottele')->create('bot_attendances', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unique();
            $table->bigInteger('chat_id')->nullable();
            $table->string('username')->nullable();
            $table->string('name')->nullable();
            $table->string('status', 20)->nullable();
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('active_ticket_id')->nullable();
            $table->boolean('expiry_notified')->default(false);
            $table->dateTime('last_auto_assigned_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('bottele')->dropIfExists('bot_attendances');
    }
};
