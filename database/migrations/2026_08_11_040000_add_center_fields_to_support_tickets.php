<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('center_status')->default('not_started')->after('status')->index();
            $table->text('center_note')->nullable()->after('note');
            $table->foreignId('center_updated_by_user_id')->nullable()->after('center_note')->constrained('users')->nullOnDelete();
            $table->timestamp('center_updated_at')->nullable()->after('center_updated_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('center_updated_by_user_id');
            $table->dropColumn(['center_status', 'center_note', 'center_updated_at']);
        });
    }
};
