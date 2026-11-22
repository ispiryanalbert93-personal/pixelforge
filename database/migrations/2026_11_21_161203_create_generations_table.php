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
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('idempotency_key')->unique();
            $table->string('status')->default('queued')->index();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images')->nullable();
            $table->json('locked_rates')->nullable();
            $table->json('usage')->nullable();
            $table->bigInteger('estimated_min_user_cost_nano')->default(0);
            $table->bigInteger('estimated_user_cost_nano')->default(0);
            $table->bigInteger('estimated_max_user_cost_nano')->default(0);
            $table->bigInteger('reserved_user_cost_nano')->default(0);
            $table->bigInteger('actual_provider_cost_nano')->nullable();
            $table->bigInteger('actual_user_cost_nano')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
