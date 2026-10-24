<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lives in database/migrations/bottele so the main `php artisan migrate` never needs the bot DB.
// Run with: php artisan migrate --database=bottele --path=database/migrations/bottele
return new class extends Migration
{
    public function up(): void
    {
        // Mirrors the "Tickets" sheet column-for-column. Timestamps are Asia/Jakarta wall-clock.
        Schema::connection('bottele')->create('bot_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_id')->unique();
            $table->dateTime('created_at')->nullable();
            $table->bigInteger('source_chat_id')->nullable();
            $table->string('source_chat_title')->nullable();
            $table->bigInteger('requester_user_id')->nullable();
            $table->string('requester_name')->nullable();
            $table->string('requester_username')->nullable();
            $table->string('category')->nullable()->index();
            $table->text('description')->nullable();
            // Free text on purpose: OPEN, ASSIGNED, ON_PROGRESS, RESOLVED, FAILED, WAITING_APPROVAL, REJECTED.
            $table->string('status', 40)->nullable()->index();
            $table->bigInteger('assigned_user_id')->nullable()->index();
            $table->string('assigned_name')->nullable();
            $table->bigInteger('cs_group_message_id')->nullable();
            $table->bigInteger('merchant_message_id')->nullable();
            $table->string('attachment_file_id')->nullable();
            $table->boolean('has_attachment')->default(false);
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('started_at')->nullable();
            // The four *_minutes columns hold SECONDS (n8n switched units but kept the names).
            // Signed: historical rows contain negatives from n8n's timezone parsing.
            $table->integer('pickup_minutes')->nullable()->comment('seconds, not minutes');
            $table->integer('investigation_lag_minutes')->nullable()->comment('seconds, not minutes');
            $table->integer('handling_minutes')->nullable()->comment('seconds, not minutes');
            $table->integer('total_resolution_minutes')->nullable()->comment('seconds, not minutes');
            $table->text('last_update')->nullable();
            $table->dateTime('last_open_reminder_at')->nullable();
            $table->text('open_reminder_message_ids')->nullable();
            $table->integer('open_reminder_count')->nullable()->default(0);
            $table->string('assignment_type', 20)->nullable();
            $table->dateTime('auto_assigned_at')->nullable();
            $table->bigInteger('auto_assigned_message_id')->nullable();
            $table->boolean('escalated')->nullable();
            $table->dateTime('escalated_at')->nullable();
            $table->string('approval_status', 40)->nullable();
            $table->bigInteger('approval_by_user_id')->nullable();
            $table->string('approval_by_name')->nullable();
            $table->text('approval_note')->nullable();
            $table->dateTime('approval_requested_at')->nullable();
            $table->dateTime('approval_completed_at')->nullable();
            $table->bigInteger('approval_group_message_id')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('bottele')->dropIfExists('bot_tickets');
    }
};
