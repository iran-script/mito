<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->bigInteger('balance')->default(0);
            $t->timestamps();
        });
        Schema::create('coin_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type', 40);
            $t->bigInteger('amount');
            $t->bigInteger('balance_before');
            $t->bigInteger('balance_after');
            $t->string('reference_type')->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('idempotency_key')->nullable()->unique();
            $t->string('code', 80);
            $t->string('description')->nullable();
            $t->json('metadata')->nullable();
            $t->foreignId('reverses_transaction_id')->nullable()->constrained('coin_transactions')->nullOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['user_id', 'created_at']);
            $t->index(['reference_type', 'reference_id']);
        });
        Schema::create('coin_feature_prices', function (Blueprint $t) {
            $t->id();
            $t->string('feature_code', 60)->unique();
            $t->unsignedInteger('coin_cost')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('coin_packages', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedInteger('base_coins');
            $t->unsignedInteger('bonus_coins')->default(0);
            $t->unsignedBigInteger('price_amount');
            $t->string('currency', 3)->default('IRR');
            $t->boolean('is_active')->default(true);
            $t->boolean('is_featured')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestampTz('starts_at')->nullable();
            $t->timestampTz('ends_at')->nullable();
            $t->timestamps();
            $t->index(['is_active', 'sort_order']);
        });
        Schema::create('coin_purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('package_id')->nullable()->constrained('coin_packages')->nullOnDelete();
            $t->unsignedBigInteger('amount_snapshot');
            $t->string('currency_snapshot', 3);
            $t->unsignedInteger('base_coins_snapshot');
            $t->unsignedInteger('bonus_coins_snapshot');
            $t->string('status', 20)->default('pending');
            $t->string('provider')->nullable();
            $t->string('provider_reference')->nullable();
            $t->string('idempotency_key')->unique();
            $t->timestampTz('paid_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
        Schema::create('economy_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('value');
            $t->timestamps();
        });
        Schema::create('telegram_identity_share_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 20)->default('pending');
            $t->timestampTz('expires_at')->nullable();
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampTz('rejected_at')->nullable();
            $t->timestamps();
            $t->index(['recipient_user_id', 'status']);
            $t->unique(['requester_user_id', 'recipient_user_id', 'status']);
        });
        Schema::create('telegram_identity_share_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('share_request_id')->constrained('telegram_identity_share_requests')->cascadeOnDelete();
            $t->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('coin_transaction_id')->constrained('coin_transactions');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['telegram_identity_share_events', 'telegram_identity_share_requests', 'economy_settings', 'coin_purchase_orders', 'coin_packages', 'coin_feature_prices', 'coin_transactions', 'wallets'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
