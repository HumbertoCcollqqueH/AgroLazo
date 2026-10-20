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
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->string('qr_path')->nullable();
            $table->string('qr_mime', 40)->nullable();
            $table->string('qr_sha256', 64)->nullable();
            $table->unsignedInteger('qr_recipient_version')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropColumn(['qr_path', 'qr_mime', 'qr_sha256', 'qr_recipient_version']);
        });
    }
};
