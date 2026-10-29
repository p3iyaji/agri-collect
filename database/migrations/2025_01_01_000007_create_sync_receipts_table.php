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
        Schema::create('sync_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->char('local_uuid', 36);
            $table->enum('status', ['pending', 'processed', 'failed'])->default('pending');
            $table->json('response')->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamps();

            $table->unique(['account_id', 'local_uuid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_receipts');
    }
};
