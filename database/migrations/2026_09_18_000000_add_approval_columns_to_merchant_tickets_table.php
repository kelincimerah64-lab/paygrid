<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->enum('approval_status', ['waiting', 'approved', 'rejected'])->nullable()->after('status');
            $table->string('approval_by')->nullable()->after('approval_status');
            $table->text('approval_note')->nullable()->after('approval_by');
            $table->timestamp('approval_completed_at')->nullable()->after('approval_note');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'approval_by', 'approval_note', 'approval_completed_at']);
        });
    }
};
