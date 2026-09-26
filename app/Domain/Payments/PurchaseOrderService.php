<?php

namespace App\Domain\Payments;

use App\Domain\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseOrderService
{
    public function create(User $user, CoinPackage $package, ?string $key = null): PurchaseOrder
    {
        if (! $package->available()) {
            throw new \DomainException('Package unavailable.');
        }

        return PurchaseOrder::create(['user_id' => $user->id, 'package_id' => $package->id, 'amount_snapshot' => $package->price_amount, 'currency_snapshot' => $package->currency, 'base_coins_snapshot' => $package->base_coins, 'bonus_coins_snapshot' => $package->bonus_coins, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    public function fulfill(PurchaseOrder $order, ?string $providerReference = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $providerReference) {
            $o = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($o->status === 'paid') {
                return $o;
            }$tx = app(WalletService::class)->credit($o->user_id, $o->base_coins_snapshot + $o->bonus_coins_snapshot, CoinTransactionType::Purchase, 'purchase', ['reference_type' => PurchaseOrder::class, 'reference_id' => $o->id, 'idempotency_key' => 'purchase:'.$o->id]);
            $o->update(['status' => 'paid', 'provider_reference' => $providerReference, 'paid_at' => now()]);

            return $o->fresh();
        });
    }
}
