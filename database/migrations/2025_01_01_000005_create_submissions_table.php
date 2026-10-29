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
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->constrained()->restrictOnDelete();
            $table->char('local_uuid', 36);
            $table->json('payload')->default('{}');
            $table->double('gps_lat')->nullable();
            $table->double('gps_lng')->nullable();
            $table->float('gps_accuracy')->nullable();
            $table->string('gps_source', 16)->default('none');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // Idempotency key of the offline sync engine (per workspace).
            $table->unique(['account_id', 'local_uuid']);
            $table->index(['account_id', 'form_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
