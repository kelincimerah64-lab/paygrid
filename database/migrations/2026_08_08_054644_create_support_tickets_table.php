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
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topup_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ticket_no')->unique();
            $table->string('reference')->nullable()->index();
            $table->string('client_reference')->nullable();
            $table->string('issue')->default('Payment pending');
            $table->enum('status', ['not_started', 'open', 'in_progress', 'done', 'cancelled'])->default('not_started')->index();
            $table->text('note')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamp('submitted_to_center_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
