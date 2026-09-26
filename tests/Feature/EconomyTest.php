<?php

namespace Tests\Feature;

use App\Domain\Payments\CoinTransaction;
use App\Domain\Payments\CoinTransactionType;
use App\Domain\Payments\FeaturePricingService;
use App\Domain\Payments\InsufficientCoinsException;
use App\Domain\Payments\WalletService;
use App\Domain\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EconomyTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_ledger_and_idempotent_debit_are_atomic(): void
    {
        $this->seed();
        $user = User::create(['telegram_user_id' => 991001]);
        $wallets = app(WalletService::class);
        $wallets->wallet($user);
        $wallets->credit($user, 5, CoinTransactionType::Bonus, 'test', ['idempotency_key' => 'credit:1']);
        $first = $wallets->debit($user, 2, 'direct_message', ['idempotency_key' => 'debit:1']);
        $second = $wallets->debit($user, 2, 'direct_message', ['idempotency_key' => 'debit:1']);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(3, $user->fresh()->wallet->balance);
        $this->assertSame(-2, $first->amount);
        $this->expectException(InsufficientCoinsException::class);
        $wallets->debit($user, 4, 'direct_message', ['idempotency_key' => 'debit:2']);
    }

    public function test_prices_are_resolved_from_database_and_refund_is_compensating(): void
    {
        $this->seed();
        $this->assertSame(2, app(FeaturePricingService::class)->cost('direct_message'));
        $user = User::create(['telegram_user_id' => 991002]);
        $wallets = app(WalletService::class);
        $wallets->credit($user, 3, CoinTransactionType::Purchase, 'purchase', ['idempotency_key' => 'purchase:1']);
        $debit = $wallets->debit($user, 1, 'direct_message', ['idempotency_key' => 'debit:3']);
        $refund = $wallets->refund($debit, 'delivery reversal');
        $this->assertSame(3, $user->fresh()->wallet->balance);
        $this->assertSame($debit->id, $refund->reverses_transaction_id);
        $this->assertCount(3, CoinTransaction::where('user_id', $user->id)->get());
    }
}
