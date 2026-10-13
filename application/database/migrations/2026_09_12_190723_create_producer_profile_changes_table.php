<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producer_profile_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('producer_id')->constrained()->restrictOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->text('changes');
            $table->text('reason');
            $table->timestampTz('changed_at');
            $table->index(['producer_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producer_profile_changes');
    }
};
