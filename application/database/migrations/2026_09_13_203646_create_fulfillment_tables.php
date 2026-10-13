<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_points', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 160);
            $t->text('description');
            $t->text('address');
            $t->foreignId('location_id')->constrained()->restrictOnDelete();
            $t->decimal('latitude', 9, 6)->nullable();
            $t->decimal('longitude', 9, 6)->nullable();
            $t->decimal('capacity', 18, 3);
            $t->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $t->time('opens_at');
            $t->time('closes_at');
            $t->boolean('active')->default(true);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
        });
        Schema::create('collection_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('collection_point_id')->constrained()->restrictOnDelete();
            $t->foreignId('scheduled_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->decimal('quantity', 18, 3);
            $t->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $t->string('status', 30)->default('scheduled');
            $t->unsignedInteger('version');
            $t->text('reason');
            $t->text('point_snapshot');
            $t->timestampsTz();
            $t->index(['collection_point_id', 'starts_at', 'ends_at']);
        });
        DB::statement("CREATE UNIQUE INDEX collection_one_active_per_order ON collection_events (order_id) WHERE status = 'scheduled'");
        Schema::create('collection_event_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('collection_event_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_allocation_id')->constrained()->restrictOnDelete();
            $t->decimal('expected_quantity', 18, 3);
            $t->timestampsTz();
            $t->unique(['collection_event_id', 'order_allocation_id'], 'collection_allocation_unique');
        });
        Schema::create('collection_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('collection_event_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_allocation_id')->constrained()->restrictOnDelete();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            foreach (['expected', 'received', 'accepted', 'rejected'] as $field) {
                $t->decimal($field.'_quantity', 18, 3);
            }
            $t->string('quality_status', 30);
            $t->timestampTz('arrived_at');
            $t->text('notes');
            $t->timestampsTz();
        });
        Schema::create('receipt_photos', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('collection_receipt_id')->constrained()->restrictOnDelete();
            $t->text('path');
            $t->string('mime', 80);
            $t->unsignedInteger('size');
            $t->string('sha256', 64);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['collection_receipt_id', 'sha256']);
        });
        Schema::create('physical_lots', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 24)->unique();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('lot_id')->constrained()->restrictOnDelete();
            $t->foreignId('collection_point_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 30)->default('ready');
            $t->timestampsTz();
        });
        Schema::create('physical_lot_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('physical_lot_id')->constrained()->restrictOnDelete();
            $t->foreignId('collection_receipt_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 18, 3);
            $t->decimal('retired_quantity', 18, 3)->default(0);
            $t->timestampsTz();
            $t->unique(['physical_lot_id', 'collection_receipt_id']);
        });
        Schema::create('shipments', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 24)->unique();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('scheduled_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 30)->default('scheduled');
            $t->unsignedInteger('version')->default(1);
            $t->text('carrier')->nullable();
            $t->text('vehicle')->nullable();
            $t->bigInteger('cost_minor')->default(0);
            $t->timestampTz('scheduled_at');
            $t->timestampTz('dispatched_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->text('notes');
            $t->timestampsTz();
        });
        Schema::create('shipment_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $t->foreignId('physical_lot_item_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 18, 3);
            foreach (['delivered', 'returned', 'lost'] as $field) {
                $t->decimal($field.'_quantity', 18, 3)->default(0);
            }
            $t->timestampsTz();
            $t->unique(['shipment_id', 'physical_lot_item_id']);
        });
        Schema::create('shipment_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $t->foreignId('shipment_item_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 40);
            $t->decimal('quantity', 18, 3)->nullable();
            $t->text('notes');
            $t->timestampTz('occurred_at');
            $t->timestampsTz();
        });
        Schema::create('logistics_incidents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_allocation_id')->constrained()->restrictOnDelete();
            $t->foreignId('collection_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('shipment_item_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('kind', 40);
            $t->decimal('quantity', 18, 3);
            $t->string('status', 30)->default('open');
            $t->text('resolution')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('resolved_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('operational_notices', function (Blueprint $t): void {
            $t->id();
            $t->string('event_key', 160)->unique();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('collection_event_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('producer_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->text('body');
            $t->string('status', 30)->default('pending');
            $t->foreignId('message_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('error_code', 80)->nullable();
            $t->uuid('notification_uuid')->unique();
            $t->timestampsTz();
        });
        Schema::create('notifications', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE collection_points ADD CONSTRAINT point_ranges CHECK (capacity > 0 AND capacity <> 'NaN'::numeric AND closes_at > opens_at AND ((latitude IS NULL AND longitude IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)))");
        DB::statement("ALTER TABLE collection_events ADD CONSTRAINT collection_window CHECK (ends_at > starts_at AND quantity > 0 AND quantity <> 'NaN'::numeric AND status IN ('scheduled','cancelled','completed'))");
        DB::statement("ALTER TABLE collection_receipts ADD CONSTRAINT receipt_balance CHECK (expected_quantity >= 0 AND expected_quantity <> 'NaN'::numeric AND received_quantity >= 0 AND received_quantity <> 'NaN'::numeric AND accepted_quantity >= 0 AND rejected_quantity >= 0 AND received_quantity = accepted_quantity + rejected_quantity AND accepted_quantity <= expected_quantity)");
        DB::statement("ALTER TABLE physical_lot_items ADD CONSTRAINT physical_balance CHECK (quantity > 0 AND quantity <> 'NaN'::numeric AND retired_quantity >= 0 AND retired_quantity <= quantity)");
        DB::statement("ALTER TABLE shipment_items ADD CONSTRAINT shipment_balance CHECK (quantity > 0 AND quantity <> 'NaN'::numeric AND delivered_quantity >= 0 AND returned_quantity >= 0 AND lost_quantity >= 0 AND delivered_quantity + returned_quantity + lost_quantity <= quantity)");
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipment_state CHECK (status IN ('scheduled','in_transit','delivered','failed') AND version > 0 AND cost_minor >= 0)");
        DB::statement('ALTER TABLE operational_notices ADD CONSTRAINT notice_recipient CHECK ((producer_id IS NULL) <> (user_id IS NULL))');
        DB::statement("ALTER TABLE logistics_incidents ADD CONSTRAINT incident_quantity CHECK (quantity > 0 AND quantity <> 'NaN'::numeric AND status IN ('open','resolved'))");
    }

    public function down(): void
    {
        foreach (['notifications', 'operational_notices', 'logistics_incidents', 'shipment_events', 'shipment_items', 'shipments', 'physical_lot_items', 'physical_lots', 'receipt_photos', 'collection_receipts', 'collection_event_items', 'collection_events', 'collection_points'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
