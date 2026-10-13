<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->enum('dimension', ['mass', 'count', 'package']);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE units ADD CONSTRAINT si_units_are_mass CHECK (code NOT IN ('kg','g','t') OR dimension = 'mass')");
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('product_categories')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
        foreach (['product_varieties', 'quality_grades'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->string('name');
                $table->string('code', 80);
                $table->boolean('active')->default(true);
                if ($name === 'quality_grades') {
                    $table->text('description')->nullable();
                    $table->text('criteria')->nullable();
                }
                $table->timestampsTz();
                $table->unique(['product_id', 'code']);
                $table->unique(['id', 'product_id']);
            });
        }
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->enum('type', ['country', 'department', 'province', 'district', 'community']);
            $table->foreignId('parent_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->string('code', 80)->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE locations ADD CONSTRAINT location_parent_required CHECK ((type = 'country' AND parent_id IS NULL) OR (type <> 'country' AND parent_id IS NOT NULL AND parent_id <> id))");
        DB::statement('CREATE UNIQUE INDEX locations_name_scope_unique ON locations (COALESCE(parent_id, 0), type, lower(name))');
        Schema::create('unit_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('to_unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('factor', 18, 9);
            $table->string('context', 120);
            $table->text('source');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('verified_at');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->index(['product_id', 'from_unit_id', 'to_unit_id', 'context', 'active'], 'conversion_scope_index');
        });
        DB::statement('ALTER TABLE unit_conversions ADD CONSTRAINT conversion_positive_factor CHECK (factor > 0)');
        DB::statement('ALTER TABLE unit_conversions ADD CONSTRAINT conversion_distinct_units CHECK (from_unit_id <> to_unit_id)');
        DB::statement('ALTER TABLE unit_conversions ADD CONSTRAINT conversion_valid_dates CHECK (valid_until IS NULL OR valid_until >= valid_from)');
    }

    public function down(): void
    {
        foreach (['unit_conversions', 'locations', 'quality_grades', 'product_varieties', 'products', 'product_categories', 'units'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
