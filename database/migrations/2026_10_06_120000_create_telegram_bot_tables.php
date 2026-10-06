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
        Schema::create('telegram_bot_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_user_id')->unique();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->bigInteger('dm_chat_id')->nullable();
            $table->bigInteger('group_chat_id')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pin_hash')->nullable();
            $table->timestamp('pin_expires_at')->nullable();
            $table->foreignId('pin_generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('joined_group_at')->nullable();
            $table->timestamp('left_group_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_bot_user_id')->constrained()->cascadeOnDelete();
            $table->date('absen_date');
            $table->timestamp('absen_at');
            $table->boolean('is_verified');
            $table->timestamps();
            $table->unique(['telegram_bot_user_id', 'absen_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_absences');
        Schema::dropIfExists('telegram_bot_users');
    }
};
