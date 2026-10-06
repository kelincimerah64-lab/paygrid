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
        Schema::table('merchant_ticket_messages', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('body');
        });

        Schema::create('merchant_ticket_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_viewed_at');
            $table->timestamps();
            $table->unique(['merchant_ticket_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_ticket_views');

        Schema::table('merchant_ticket_messages', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
    }
};
