<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 20);
            $table->string('account', 100);
            $table->string('channel', 20);
            $table->text('phone');
            $table->char('phone_lookup', 64);
            $table->foreignId('producer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('state', 40)->default('NEW');
            $table->text('context')->nullable();
            $table->string('consent', 20)->default('pending');
            $table->string('notice_version', 80)->nullable();
            $table->timestampTz('consented_at')->nullable();
            $table->boolean('human_assigned')->default(false);
            $table->timestampTz('last_inbound_at')->nullable();
            $table->timestampTz('last_processed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'account', 'channel', 'phone_lookup'], 'conversations_contact_unique');
        });
        Schema::table('producer_consents', function (Blueprint $table): void {
            $table->unsignedBigInteger('producer_id')->nullable()->change();
            $table->unsignedBigInteger('recorded_by')->nullable()->change();
            $table->foreignId('conversation_id')->nullable()->constrained()->restrictOnDelete();
        });
        DB::statement("ALTER TABLE producer_consents DROP CONSTRAINT producer_consents_channel_check, ADD CONSTRAINT producer_consents_channel_check CHECK (channel IN ('assisted_web','simulator','whatsapp','sms')), ADD CONSTRAINT producer_consents_subject_check CHECK (producer_id IS NOT NULL OR conversation_id IS NOT NULL)");
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->restrictOnDelete();
            $table->string('direction', 10);
            $table->string('provider', 20);
            $table->string('account', 100);
            $table->string('external_id', 200)->nullable();
            $table->string('deduplication_key', 200)->unique();
            $table->text('body');
            $table->text('metadata')->nullable();
            $table->string('status', 30)->default('received');
            $table->string('error_code', 80)->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('lease_until')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'account', 'direction', 'external_id'], 'messages_provider_id_unique');
            $table->index(['conversation_id', 'direction', 'processed_at', 'id']);
        });
        Schema::create('message_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->restrictOnDelete();
            $table->uuid('token')->unique();
            $table->string('outcome', 30);
            $table->string('error_code', 80)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
        });
        Schema::create('message_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 20);
            $table->string('account', 100);
            $table->string('external_id', 200);
            $table->string('status', 30);
            $table->char('event_key', 64)->unique();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['provider', 'account', 'external_id']);
        });
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 200)->unique();
            $table->string('type', 30);
            $table->text('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('lease_until')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestampsTz();
            $table->index(['status', 'available_at', 'lease_until']);
        });
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_provider_check CHECK (provider IN ('fake','evolution','twilio')), ADD CONSTRAINT conversations_channel_check CHECK (channel IN ('simulator','whatsapp','sms')), ADD CONSTRAINT conversations_consent_check CHECK (consent IN ('pending','granted','declined'))");
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_direction_check CHECK (direction IN ('inbound','outbound')), ADD CONSTRAINT messages_status_check CHECK (status IN ('received','processed','queued','sending','accepted','sent','delivered','read','failed','uncertain','blocked','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('message_receipts');
        Schema::dropIfExists('message_attempts');
        Schema::dropIfExists('messages');
        Schema::table('producer_consents', function (Blueprint $table): void {
            $table->dropForeign(['conversation_id']);
        });
        DB::statement('ALTER TABLE producer_consents DROP CONSTRAINT producer_consents_subject_check');
        Schema::table('producer_consents', function (Blueprint $table): void {
            $table->dropColumn('conversation_id');
        });
        Schema::dropIfExists('conversations');
    }
};
