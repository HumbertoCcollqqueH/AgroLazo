<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_prices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('variety_id')->constrained('product_varieties')->restrictOnDelete();
            $t->foreignId('quality_grade_id')->constrained()->restrictOnDelete();
            $t->foreignId('location_id')->constrained()->restrictOnDelete();
            $t->foreignId('unit_id')->constrained()->restrictOnDelete();
            $t->decimal('unit_price', 18, 6);
            $t->string('currency', 3)->default('PEN');
            $t->string('market', 160);
            $t->date('observed_on');
            $t->text('source');
            $t->string('provider', 30)->default('manual');
            $t->boolean('synthetic')->default(false);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->index(['product_id', 'observed_on']);
        });
        Schema::create('pricing_policies', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 160);
            $t->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $t->string('allocation_rule', 30)->default('fifo');
            $t->decimal('fee_unit_price', 18, 6)->default(0);
            $t->bigInteger('logistics_minor')->default(0);
            $t->decimal('discount_threshold', 18, 3)->default(0);
            $t->bigInteger('discount_minor')->default(0);
            $t->integer('tax_bps')->default(0);
            $t->text('tax_basis');
            $t->text('disclosure');
            $t->string('delivery_mode', 30)->default('collection');
            $t->boolean('synthetic')->default(false);
            $t->boolean('active')->default(true);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
        });
        Schema::create('quotes', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 24)->unique();
            $t->foreignId('buyer_id')->constrained()->restrictOnDelete();
            $t->foreignId('lot_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('lot_version');
            $t->foreignId('pricing_policy_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 18, 3);
            $t->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $t->string('currency', 3)->default('PEN');
            foreach (['subtotal', 'fee', 'logistics', 'discount', 'tax', 'total'] as $field) {
                $t->bigInteger($field.'_minor');
            }
            $t->decimal('weighted_unit_price', 18, 6);
            $t->string('pricing_version', 40)->default('exact-lines-v1');
            $t->text('pricing_snapshot');
            $t->text('reference_snapshot')->nullable();
            $t->text('delivery_details');
            $t->string('delivery_mode', 30);
            $t->string('payment_method', 30);
            $t->boolean('synthetic');
            $t->string('status', 30)->default('issued');
            $t->timestampTz('issued_at');
            $t->timestampTz('expires_at')->index();
            $t->timestampsTz();
        });
        Schema::create('quote_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('quote_id')->constrained()->restrictOnDelete();
            $t->foreignId('lot_offer_id')->constrained()->restrictOnDelete();
            $t->foreignId('agreement_id')->constrained('offer_price_agreements')->restrictOnDelete();
            $t->unsignedInteger('offer_version');
            $t->decimal('quantity', 18, 3);
            $t->decimal('gross_unit_price', 18, 6);
            $t->decimal('producer_unit_cost', 18, 6);
            $t->bigInteger('gross_minor');
            $t->bigInteger('producer_cost_minor');
            $t->bigInteger('producer_net_minor');
            $t->text('terms_snapshot');
            $t->timestampsTz();
            $t->unique(['quote_id', 'lot_offer_id']);
        });
        Schema::create('reservations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('quote_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('buyer_id')->constrained()->restrictOnDelete();
            $t->string('status', 30)->default('active');
            $t->timestampTz('expires_at');
            $t->timestampsTz();
            $t->index(['status', 'expires_at']);
        });
        Schema::create('reservation_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('reservation_id')->constrained()->restrictOnDelete();
            $t->foreignId('quote_item_id')->constrained()->restrictOnDelete();
            $t->foreignId('lot_offer_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 18, 3);
            $t->timestampsTz();
            $t->unique(['reservation_id', 'quote_item_id']);
        });
        Schema::create('orders', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 24)->unique();
            $t->foreignId('buyer_id')->constrained()->restrictOnDelete();
            $t->foreignId('quote_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('reservation_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->string('status', 30)->default('confirmed');
            $t->unsignedInteger('version')->default(1);
            $t->string('currency', 3)->default('PEN');
            $t->bigInteger('original_total_minor');
            $t->bigInteger('total_minor');
            $t->string('payment_method', 30);
            $t->text('delivery_details');
            $t->boolean('synthetic');
            $t->timestampTz('confirmed_at');
            $t->timestampsTz();
        });
        Schema::create('order_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('lot_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 18, 3);
            $t->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $t->jsonb('product_snapshot');
            $t->timestampsTz();
        });
        Schema::create('order_allocations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $t->foreignId('lot_offer_id')->constrained()->restrictOnDelete();
            $t->foreignId('quote_item_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('agreement_id')->constrained('offer_price_agreements')->restrictOnDelete();
            $t->decimal('quantity', 18, 3);
            $t->decimal('released_quantity', 18, 3)->default(0);
            $t->decimal('gross_unit_price', 18, 6);
            $t->decimal('producer_unit_cost', 18, 6);
            $t->text('terms_snapshot');
            $t->timestampsTz();
        });
        Schema::create('order_adjustments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('order_version');
            $t->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->string('status', 30)->default('proposed');
            $t->bigInteger('reduction_minor');
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('order_adjustment_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_adjustment_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_allocation_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 18, 3);
            $t->boolean('retire');
            $t->bigInteger('reduction_minor');
            $t->timestampsTz();
            $t->unique(['order_adjustment_id', 'order_allocation_id'], 'adjustment_allocation_unique');
        });
        Schema::create('operation_keys', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('operation', 80);
            $t->uuid('key');
            $t->string('payload_hash', 64);
            $t->string('resource_type', 120);
            $t->unsignedBigInteger('resource_id');
            $t->timestampsTz();
            $t->unique(['actor_id', 'operation', 'key']);
        });
        Schema::create('commerce_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('lot_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 80);
            $t->text('details');
            $t->timestampTz('occurred_at');
            $t->timestampsTz();
        });

        DB::statement("ALTER TABLE pricing_policies ADD CONSTRAINT pricing_policy_rules CHECK (allocation_rule IN ('fifo','lowest_price') AND delivery_mode IN ('collection','delivery') AND tax_bps BETWEEN 0 AND 10000 AND logistics_minor >= 0 AND discount_minor >= 0 AND discount_threshold >= 0 AND discount_threshold <> 'NaN'::numeric AND fee_unit_price >= 0 AND fee_unit_price <> 'NaN'::numeric)");
        DB::statement("ALTER TABLE market_prices ADD CONSTRAINT reference_positive CHECK (unit_price > 0 AND unit_price <> 'NaN'::numeric AND currency = 'PEN')");
        DB::statement('ALTER TABLE quotes ADD CONSTRAINT quote_balance CHECK (subtotal_minor >= 0 AND fee_minor >= 0 AND logistics_minor >= 0 AND discount_minor >= 0 AND tax_minor >= 0 AND total_minor >= 0 AND total_minor = subtotal_minor + fee_minor + logistics_minor + tax_minor - discount_minor AND expires_at > issued_at)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT order_totals CHECK (original_total_minor >= 0 AND total_minor >= 0 AND total_minor <= original_total_minor AND version > 0)');
        DB::statement('ALTER TABLE quote_items ADD CONSTRAINT quote_item_balance CHECK (gross_minor >= 0 AND producer_cost_minor >= 0 AND producer_net_minor >= 0 AND producer_net_minor = gross_minor - producer_cost_minor)');
        foreach (['quotes', 'quote_items', 'reservation_items', 'order_items', 'order_allocations', 'order_adjustment_items'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_finite_quantity CHECK (quantity > 0 AND quantity <> 'NaN'::numeric)");
        }
        DB::statement("ALTER TABLE order_allocations ADD CONSTRAINT allocation_balance CHECK (released_quantity >= 0 AND released_quantity <= quantity AND released_quantity <> 'NaN'::numeric)");
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_status CHECK (status IN ('active','expired','cancelled','converted'))");
        DB::statement("ALTER TABLE order_adjustments ADD CONSTRAINT adjustment_status CHECK (status IN ('proposed','accepted','rejected') AND reduction_minor >= 0)");
    }

    public function down(): void
    {
        foreach (['commerce_events', 'operation_keys', 'order_adjustment_items', 'order_adjustments', 'order_allocations', 'order_items', 'orders', 'reservation_items', 'reservations', 'quote_items', 'quotes', 'pricing_policies', 'market_prices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
