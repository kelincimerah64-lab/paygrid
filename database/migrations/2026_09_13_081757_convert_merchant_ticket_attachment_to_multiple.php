<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('description');
        });

        DB::table('merchant_tickets')->whereNotNull('attachment_path')->orderBy('id')->get()->each(function ($row) {
            DB::table('merchant_tickets')->where('id', $row->id)->update([
                'attachments' => json_encode([[
                    'disk' => $row->attachment_disk,
                    'path' => $row->attachment_path,
                    'name' => $row->attachment_name,
                ]]),
            ]);
        });

        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->dropColumn(['attachment_disk', 'attachment_path', 'attachment_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->string('attachment_disk')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
        });

        DB::table('merchant_tickets')->whereNotNull('attachments')->orderBy('id')->get()->each(function ($row) {
            $first = json_decode($row->attachments, true)[0] ?? null;
            if ($first) {
                DB::table('merchant_tickets')->where('id', $row->id)->update([
                    'attachment_disk' => $first['disk'] ?? null,
                    'attachment_path' => $first['path'] ?? null,
                    'attachment_name' => $first['name'] ?? null,
                ]);
            }
        });

        Schema::table('merchant_tickets', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }
};
