<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('producers', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        Schema::create('coordination_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('subject', 160);
            $table->string('status', 20)->default('open');
            $table->timestampTz('customer_seen_at')->nullable();
            $table->timestampTz('team_seen_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('coordination_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coordination_thread_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->uuid('submission_key')->unique();
            $table->timestampsTz();
        });
        DB::table('roles')->insertOrIgnore(['code' => 'producer', 'name' => 'Productor', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coordination_messages');
        Schema::dropIfExists('coordination_threads');
        Schema::table('producers', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
    }
};
