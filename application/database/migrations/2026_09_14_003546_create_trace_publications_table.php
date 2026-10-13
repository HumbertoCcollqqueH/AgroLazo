<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trace_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('physical_lot_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->text('token');
            $table->string('token_lookup', 64)->unique();
            $table->jsonb('public_snapshot');
            $table->timestampTz('published_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trace_publications');
    }
};
