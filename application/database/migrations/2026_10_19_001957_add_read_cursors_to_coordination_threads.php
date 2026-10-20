<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('coordination_threads', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_seen_message_id')->nullable();
            $table->unsignedBigInteger('team_seen_message_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coordination_threads', function (Blueprint $table): void {
            $table->dropColumn(['customer_seen_message_id', 'team_seen_message_id']);
        });
    }
};
