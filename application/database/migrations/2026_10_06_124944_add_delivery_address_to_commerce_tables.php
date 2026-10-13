<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['quotes', 'orders'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->text('delivery_address')->nullable();
            });
        }
        Schema::table('collection_event_items', function (Blueprint $table) {
            $table->text('pickup_address')->nullable();
            $table->timestampTz('pickup_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('collection_event_items', function (Blueprint $table) {
            $table->dropColumn(['pickup_address', 'pickup_at']);
        });
        foreach (['quotes', 'orders'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('delivery_address');
            });
        }
    }
};
