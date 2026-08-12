<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->enum('provisioning_status', ['not_started', 'pending', 'processing', 'success', 'failed'])->default('not_started')->after('approval_status')->index();
            $table->text('provisioning_error')->nullable()->after('provisioning_status');
            $table->unsignedInteger('provisioning_attempts')->default(0)->after('provisioning_error');
            $table->timestamp('provisioned_at')->nullable()->after('provisioning_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['provisioning_status', 'provisioning_error', 'provisioning_attempts', 'provisioned_at']);
        });
    }
};
