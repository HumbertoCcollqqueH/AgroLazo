<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('source_key')->unique();
            $table->char('source_hash', 64);
            $table->string('channel', 24)->default('assisted_web');
            $table->foreignId('registered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('producer_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variety_id')->nullable()->constrained('product_varieties')->restrictOnDelete();
            $table->foreignId('quality_grade_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('normalized_quantity', 18, 3)->nullable();
            $table->decimal('conversion_factor', 18, 9)->nullable();
            $table->foreignId('conversion_id')->nullable()->constrained('unit_conversions')->restrictOnDelete();
            $table->string('conversion_source', 2000)->nullable();
            $table->date('conversion_date');
            $table->string('context', 120)->default('');
            $table->date('available_from');
            $table->date('available_until');
            $table->decimal('requested_unit_price', 18, 6)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['draft', 'pending_verification', 'needs_information', 'verified', 'published', 'rejected', 'cancelled', 'expired'])->default('pending_verification');
            $table->unsignedInteger('version')->default(1);
            $table->decimal('withdrawn_quantity', 18, 3)->default(0);
            $table->timestampsTz();
            $table->index(['status', 'product_id', 'available_until']);
            $table->foreign(['variety_id', 'product_id'])->references(['id', 'product_id'])->on('product_varieties')->restrictOnDelete();
            $table->foreign(['quality_grade_id', 'product_id'])->references(['id', 'product_id'])->on('quality_grades')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offer_quantities_valid CHECK (quantity > 0 AND (normalized_quantity IS NULL OR normalized_quantity > 0) AND (conversion_factor IS NULL OR conversion_factor > 0) AND withdrawn_quantity >= 0 AND (normalized_quantity IS NULL OR withdrawn_quantity <= normalized_quantity))');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offer_dates_valid CHECK (available_until >= available_from)');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offer_requested_price_valid CHECK (requested_unit_price IS NULL OR requested_unit_price > 0)');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offer_version_positive CHECK (version > 0)');
        Schema::create('offer_price_agreements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('offer_version');
            $table->unsignedInteger('version');
            $table->decimal('gross_unit_price', 18, 6);
            $table->decimal('producer_unit_cost', 18, 6);
            $table->decimal('net_unit_price', 18, 6);
            $table->char('currency', 3)->default('PEN');
            $table->text('terms');
            $table->timestampTz('valid_until');
            $table->enum('status', ['proposed', 'accepted', 'rejected'])->default('proposed');
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('evidence')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['offer_id', 'version']);
        });
        DB::statement("ALTER TABLE offer_price_agreements ADD CONSTRAINT agreement_prices_valid CHECK (gross_unit_price > 0 AND producer_unit_cost >= 0 AND net_unit_price > 0 AND net_unit_price = gross_unit_price - producer_unit_cost AND currency = 'PEN')");
        Schema::create('offer_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->unsignedInteger('offer_version');
            $table->text('changes');
            $table->text('reason');
            $table->timestampTz('occurred_at');
        });
        Schema::create('offer_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('path');
            $table->string('mime', 40);
            $table->unsignedInteger('size');
            $table->text('caption')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        foreach (['offer_photos', 'offer_histories', 'offer_price_agreements', 'offers'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
