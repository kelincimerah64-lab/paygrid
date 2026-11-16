<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_configs', function (Blueprint $table) {
            $table->string('name')->nullable()->after('ma_user_id');
            $table->foreignId('agent_id')->nullable()->after('name')->constrained('agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commission_configs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
            $table->dropColumn('name');
        });
    }
};
