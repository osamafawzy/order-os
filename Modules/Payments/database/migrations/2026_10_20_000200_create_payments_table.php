<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // Restrict, not cascade: the database itself refuses to delete an
            // order that has payments, behind the service's own check.
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('method', 50);
            $table->string('status', 20)->default('pending');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('transaction_id')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->string('failure_message')->nullable();
            $table->json('meta')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['user_id', 'status', 'created_at']);
            $table->index('method');
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('payment_gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            // Nullable: "not overridden" is different from "switched off".
            $table->boolean('enabled')->nullable();
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_settings');
        Schema::dropIfExists('payments');
    }
};
