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
        Schema::create('producer_payout_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producer_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->uuid('submission_key')->unique();
            $table->char('request_hash', 64);
            $table->text('holder_name');
            $table->text('yape_phone');
            $table->text('qr_path')->nullable();
            $table->string('qr_mime', 30)->nullable();
            $table->char('qr_sha256', 64)->nullable();
            $table->timestampTz('created_at');
            $table->unique(['producer_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producer_payout_profiles');
    }
};
