<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 100);
            $t->text('recipient');
            $t->text('instructions');
            $t->boolean('active')->default(true);
            $t->boolean('live_ready')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
        });
        Schema::create('payments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->bigInteger('amount_minor');
            $t->string('currency', 3)->default('PEN');
            $t->text('operation_reference');
            $t->string('reference_lookup', 64)->unique();
            $t->timestampTz('occurred_at');
            $t->text('recipient_snapshot');
            $t->string('status', 30)->default('pending');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('confirmed_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('payment_proofs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_id')->constrained()->restrictOnDelete();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->text('path');
            $t->string('mime', 80);
            $t->unsignedInteger('size');
            $t->string('sha256', 64)->unique();
            $t->timestampsTz();
        });
        Schema::create('payment_refunds', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_id')->constrained()->restrictOnDelete();
            $t->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $t->bigInteger('amount_minor');
            $t->string('status', 30)->default('authorized');
            $t->unsignedInteger('version')->default(1);
            $t->text('reason');
            $t->text('operation_reference')->nullable();
            $t->string('reference_lookup', 64)->nullable()->unique();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('confirmed_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('producer_settlements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('producer_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('snapshot_hash', 64);
            $t->decimal('quantity', 18, 3);
            $t->bigInteger('gross_minor');
            $t->bigInteger('agreed_cost_minor');
            $t->bigInteger('net_minor');
            $t->string('currency', 3)->default('PEN');
            $t->string('status', 30)->default('draft');
            $t->foreignId('calculated_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->unique(['order_id', 'producer_id', 'version']);
        });
        Schema::create('producer_settlement_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('producer_settlement_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_allocation_id')->constrained()->restrictOnDelete();
            $t->foreignId('collection_receipt_id')->constrained()->restrictOnDelete();
            $t->foreignId('agreement_id')->constrained('offer_price_agreements')->restrictOnDelete();
            $t->decimal('quantity', 18, 3);
            $t->bigInteger('gross_minor');
            $t->bigInteger('agreed_cost_minor');
            $t->bigInteger('net_minor');
            $t->text('terms_snapshot');
            $t->timestampsTz();
            $t->unique(['producer_settlement_id', 'collection_receipt_id'], 'settlement_receipt_unique');
        });
        Schema::create('producer_payouts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('producer_settlement_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('producer_id')->constrained()->restrictOnDelete();
            $t->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $t->bigInteger('amount_minor');
            $t->string('status', 30)->default('authorized');
            $t->unsignedInteger('version')->default(1);
            $t->text('reason');
            $t->text('operation_reference')->nullable();
            $t->string('reference_lookup', 64)->nullable()->unique();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('confirmed_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('payment_reviews', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 80);
            $t->text('notes');
            $t->text('details');
            $t->timestampTz('occurred_at');
            $t->timestampsTz();
        });
        Schema::table('orders', function (Blueprint $t): void {
            $t->foreignId('deferred_payment_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->bigInteger('deferred_payment_limit_minor')->default(0);
            $t->timestampTz('deferred_payment_due_at')->nullable();
            $t->text('deferred_payment_reason')->nullable();
        });
        foreach (['payments', 'payment_refunds', 'producer_payouts'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_amount CHECK (amount_minor > 0 AND version > 0)");
        }
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payment_status CHECK (status IN ('pending','proof_uploaded','under_review','confirmed','rejected','partially_refunded','refunded') AND currency = 'PEN')");
        foreach (['payment_refunds', 'producer_payouts'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_status CHECK (status IN ('authorized','uncertain','confirmed','failed'))");
        }
        foreach (['producer_settlements', 'producer_settlement_items'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_balance CHECK (quantity > 0 AND quantity <> 'NaN'::numeric AND gross_minor >= 0 AND agreed_cost_minor >= 0 AND net_minor >= 0 AND net_minor = gross_minor - agreed_cost_minor)");
        }
        DB::statement("ALTER TABLE producer_settlements ADD CONSTRAINT settlement_status CHECK (status IN ('draft','approved') AND version > 0)");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('deferred_payment_approved_by');
            $t->dropColumn(['deferred_payment_limit_minor', 'deferred_payment_due_at', 'deferred_payment_reason']);
        });
        foreach (['payment_reviews', 'producer_payouts', 'producer_settlement_items', 'producer_settlements', 'payment_refunds', 'payment_proofs', 'payments', 'payment_methods'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
