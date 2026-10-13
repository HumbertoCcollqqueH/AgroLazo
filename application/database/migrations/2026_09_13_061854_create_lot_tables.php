<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lots', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->uuid('source_key')->unique();
            $table->char('source_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variety_id')->constrained('product_varieties')->restrictOnDelete();
            $table->foreignId('quality_grade_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->date('available_from');
            $table->date('available_until');
            $table->enum('status', ['draft', 'published', 'paused', 'cancelled', 'expired'])->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('allow_price_variation')->default(false);
            $table->string('compatibility_rule', 60)->default('exact-locality-v1');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['variety_id', 'product_id'])->references(['id', 'product_id'])->on('product_varieties')->restrictOnDelete();
            $table->foreign(['quality_grade_id', 'product_id'])->references(['id', 'product_id'])->on('quality_grades')->restrictOnDelete();
            $table->index(['status', 'product_id', 'available_until']);
        });
        DB::statement('ALTER TABLE lots ADD CONSTRAINT lot_window_valid CHECK (available_until >= available_from AND version > 0)');
        Schema::create('lot_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_id')->constrained('offer_price_agreements')->restrictOnDelete();
            $table->unsignedInteger('offer_version');
            $table->decimal('quantity', 18, 3);
            $table->decimal('released_quantity', 18, 3)->default(0);
            $table->decimal('retired_quantity', 18, 3)->default(0);
            $table->timestampsTz();
            $table->unique(['lot_id', 'offer_id']);
            $table->index('offer_id');
        });
        DB::statement('ALTER TABLE lot_offers ADD CONSTRAINT lot_portion_quantities_valid CHECK (quantity > 0 AND released_quantity >= 0 AND retired_quantity >= 0 AND released_quantity + retired_quantity <= quantity AND offer_version > 0)');
        Schema::create('lot_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 60);
            $table->unsignedInteger('lot_version');
            $table->text('changes');
            $table->text('reason');
            $table->timestampTz('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_histories');
        Schema::dropIfExists('lot_offers');
        Schema::dropIfExists('lots');
    }
};
