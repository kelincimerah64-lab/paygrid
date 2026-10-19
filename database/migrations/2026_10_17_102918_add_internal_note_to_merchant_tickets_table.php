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
        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->text('internal_note')->nullable()->after('closed_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->dropColumn('internal_note');
        });
    }
};
