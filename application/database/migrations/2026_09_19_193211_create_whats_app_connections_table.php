<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('integration', 32);
            $table->string('instance_name', 100)->unique();
            $table->string('status', 32)->default('pending');
            $table->boolean('automation_enabled')->default(false);
            $table->text('notice_text');
            $table->string('notice_version', 80);
            $table->boolean('notice_approved')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->string('last_error', 100)->nullable();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_connections');
    }
};
