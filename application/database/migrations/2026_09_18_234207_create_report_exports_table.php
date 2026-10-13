<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('key');
            $table->string('payload_hash', 64);
            $table->string('report', 30);
            $table->string('format', 8);
            $table->text('filters');
            $table->string('status', 16)->default('pending');
            $table->string('path')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('expires_at');
            $table->string('failure_code', 40)->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'key']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
