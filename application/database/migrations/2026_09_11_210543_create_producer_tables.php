<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producer_identity_keys', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->char('fingerprint', 64);
            $table->timestampTz('created_at');
        });
        DB::statement('ALTER TABLE producer_identity_keys ADD CONSTRAINT producer_identity_single_key CHECK (id = 1)');

        Schema::create('producers', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name', 150);
            $table->text('dni');
            $table->char('dni_lookup', 64)->unique();
            $table->text('phone');
            $table->char('phone_lookup', 64)->unique();
            $table->enum('type', ['individual', 'family', 'association_member', 'cooperative', 'other']);
            $table->enum('status', ['pending_verification', 'verified', 'rejected', 'suspended', 'archived'])->default('pending_verification')->index();
            $table->foreignId('registered_by')->constrained('users')->restrictOnDelete();
            $table->string('association', 150)->nullable();
            $table->text('email')->nullable();
            $table->enum('sex', ['female', 'male', 'unspecified'])->nullable();
            $table->date('birth_date')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestampsTz();
        });

        Schema::create('producer_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('producer_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('community')->nullable();
            $table->text('address')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until')->nullable();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX producer_locations_one_current ON producer_locations (producer_id) WHERE valid_until IS NULL');
        DB::statement('ALTER TABLE producer_locations ADD CONSTRAINT producer_location_valid_dates CHECK (valid_until IS NULL OR valid_until >= valid_from)');
        DB::statement('ALTER TABLE producer_locations ADD CONSTRAINT producer_location_coordinates CHECK ((latitude IS NULL AND longitude IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180))');

        Schema::create('producer_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('producer_id')->constrained()->restrictOnDelete();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->enum('result', ['pending_verification', 'verified', 'rejected', 'suspended', 'archived']);
            $table->enum('method', ['manual_document', 'field_visit', 'assisted_call', 'administrative']);
            $table->text('notes');
            $table->timestampTz('verified_at');
            $table->index(['producer_id', 'verified_at']);
        });

        Schema::create('producer_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('producer_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 80);
            $table->string('notice_version', 80);
            $table->enum('channel', ['assisted_web']);
            $table->enum('decision', ['granted', 'declined']);
            $table->timestampTz('recorded_at');
            $table->index(['producer_id', 'purpose', 'recorded_at']);
        });

        Schema::create('producer_phone_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('producer_id')->constrained()->restrictOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->text('previous_phone');
            $table->text('new_phone');
            $table->text('reason');
            $table->timestampTz('changed_at');
            $table->index(['producer_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        foreach (['producer_phone_changes', 'producer_consents', 'producer_verifications', 'producer_locations', 'producers', 'producer_identity_keys'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
