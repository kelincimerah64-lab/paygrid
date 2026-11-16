<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per group per broadcast, so each group's copy can be deleted on its own.
        Schema::create('telegram_broadcast_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id')->index();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message');
            $table->string('group_name');
            $table->string('chat_id');
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('status', 20);
            $table->timestamp('deleted_from_telegram_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_broadcast_messages');
    }
};
