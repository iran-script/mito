<?php

namespace App\Domain\Admin;

use App\Domain\Memberships\GoldMembershipService;
use App\Domain\Memberships\Membership;
use App\Domain\Payments\CoinPackage;
use App\Domain\Payments\CoinTransaction;
use App\Domain\Payments\CoinTransactionType;
use App\Domain\Payments\EconomyAdminService;
use App\Domain\Payments\PaidFeature;
use App\Domain\Payments\WalletService;
use App\Domain\Users\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FinanceService
{
    public function __construct(private AdminAuthorization $auth, private AdminAudit $audit) {}

    public function wallet(AdminUser $a, User $user, int $amount, bool $credit, string $reason, string $key): CoinTransaction
    {
        $this->auth->authorize($a, 'finance');
        $reason = $this->auth->reason($reason);
        if ($amount < 1 || $amount > 1000000000 || ! preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $key)) {
            throw new \DomainException('Positive amount and valid operation key required.');
        }

        return DB::transaction(function () use ($a, $user, $amount, $credit, $reason, $key) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $key = 'admin:'.$a->id.':'.$key;
            if ($old = CoinTransaction::where('idempotency_key', $key)->first()) {
                if ($old->user_id !== $user->id || $old->amount !== ($credit ? $amount : -$amount)) {
                    throw new \DomainException('Operation key already used.');
                }

                return $old;
            }
            $context = ['idempotency_key' => $key, 'description' => mb_substr($reason, 0, 255), 'metadata' => ['admin_user_id' => $a->id]];
            $tx = $credit ? app(WalletService::class)->credit($user, $amount, CoinTransactionType::AdminCredit, 'admin_credit', $context) : app(WalletService::class)->debit($user, $amount, 'admin_debit', $context);
            $this->audit->record($a, $credit ? 'wallet.credit' : 'wallet.debit', 'coin_transaction', $tx->id, $reason, ['amount' => $tx->amount, 'user_id' => $user->id]);

            return $tx;
        });
    }

    public function refund(AdminUser $a, CoinTransaction $transaction, string $reason): CoinTransaction
    {
        $this->auth->authorize($a, 'finance');
        $reason = $this->auth->reason($reason);

        return DB::transaction(function () use ($a, $transaction, $reason) {
            $tx = CoinTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($tx->amount >= 0) {
                throw new \DomainException('Only a debit can be refunded.');
            }
            if ($old = CoinTransaction::where('reverses_transaction_id', $tx->id)->first()) {
                return $old;
            }
            $refund = app(WalletService::class)->refund($tx, mb_substr($reason, 0, 255));
            $this->audit->record($a, 'wallet.refund', 'coin_transaction', $refund->id, $reason, ['original_id' => $tx->id]);

            return $refund;
        });
    }

    public function price(AdminUser $a, PaidFeature $feature, int $cost, bool $active, string $reason): void
    {
        $this->auth->authorize($a, 'finance');
        $reason = $this->auth->reason($reason);
        if ($cost < 0 || $cost > 1000000000) {
            throw new \DomainException('Invalid coin cost.');
        }
        DB::transaction(function () use ($a, $feature, $cost, $active, $reason) {
            app(EconomyAdminService::class)->setPrice($feature, $cost, $active);
            $this->audit->record($a, 'pricing.change', 'feature_price', null, $reason, ['feature' => $feature->value, 'cost' => $cost, 'active' => $active]);
        });
    }

    public function package(AdminUser $a, ?CoinPackage $package, array $data, string $reason): CoinPackage
    {
        $this->auth->authorize($a, 'finance');
        $reason = $this->auth->reason($reason);
        $v = Validator::make($data, ['name' => 'required|string|max:255', 'base_coins' => 'required|integer|min:1|max:1000000000', 'bonus_coins' => 'required|integer|min:0|max:1000000000', 'price_amount' => 'required|integer|min:1|max:1000000000000', 'currency' => 'required|regex:/^[A-Z]{3}$/', 'is_active' => 'required|boolean', 'is_featured' => 'required|boolean', 'sort_order' => 'required|integer|min:0|max:1000000', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date'])->validate();

        if (! empty($v['starts_at']) && ! empty($v['ends_at']) && CarbonImmutable::parse($v['ends_at'])->lessThanOrEqualTo(CarbonImmutable::parse($v['starts_at']))) {
            throw ValidationException::withMessages(['ends_at' => 'End must be after start.']);
        }

        return DB::transaction(function () use ($a, $package, $v, $reason) {
            $p = $package ? CoinPackage::whereKey($package->id)->lockForUpdate()->firstOrFail() : new CoinPackage;
            $p->fill($v)->save();
            $this->audit->record($a, 'package.save', 'coin_package', $p->id, $reason, $v);

            return $p;
        });
    }

    public function setting(AdminUser $a, string $key, int $value, string $reason): void
    {
        $this->auth->authorize($a, 'finance');
        $reason = $this->auth->reason($reason);
        $bounds = ['signup_bonus' => [0, 1000000], 'gold_duration_days' => [1, 3650], 'gold_bulk_max_recipients_per_action' => [1, 100], 'gold_bulk_chat_requests_per_day' => [0, 10000], 'gold_bulk_direct_messages_per_day' => [0, 10000], 'gold_bulk_actions_cooldown_seconds' => [0, 86400], 'gold_priority_enabled' => [0, 1]];
        if (! isset($bounds[$key]) || $value < $bounds[$key][0] || $value > $bounds[$key][1]) {
            throw new \DomainException('Invalid setting.');
        }
        DB::transaction(function () use ($a, $key, $value, $reason) {
            DB::table('economy_settings')->updateOrInsert(['key' => $key], ['value' => (string) $value, 'updated_at' => now(), 'created_at' => now()]);
            $this->audit->record($a, 'setting.change', 'economy_setting', null, $reason, ['key' => $key, 'value' => $value]);
        });
    }

    public function grantGold(AdminUser $a, User $user, ?int $days, string $reason): Membership
    {
        $this->auth->authorize($a, 'finance');
        $reason = $this->auth->reason($reason);
        $days ??= (int) (DB::table('economy_settings')->where('key', 'gold_duration_days')->value('value') ?? 30);
        if ($days < 1 || $days > 3650) {
            throw new \DomainException('Invalid Gold duration.');
        }

        return DB::transaction(function () use ($a, $user, $days, $reason) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $m = app(GoldMembershipService::class)->activate($user, now()->addDays($days), 'admin');
            $this->audit->record($a, 'gold.grant', 'membership', $m->id, $reason, ['days' => $days, 'user_id' => $user->id]);

            return $m;
        });
    }

    public function revokeGold(AdminUser $a, Membership $membership, string $reason): void
    {
        $this->auth->authorize($a, 'finance');
        $reason = $this->auth->reason($reason);
        DB::transaction(function () use ($a, $membership, $reason) {
            $m = Membership::whereKey($membership->id)->lockForUpdate()->firstOrFail();
            if ($m->status->value === 'cancelled') {
                return;
            }$m->update(['status' => 'cancelled']);
            $this->audit->record($a, 'gold.revoke', 'membership', $m->id, $reason);
        });
    }
}
