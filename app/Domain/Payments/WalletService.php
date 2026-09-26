<?php

namespace App\Domain\Payments;

use App\Domain\Users\User;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function wallet(User|int $user): Wallet
    {
        $id = $user instanceof User ? $user->id : $user;

        return Wallet::firstOrCreate(['user_id' => $id]);
    }

    public function credit(User|int $user, int $amount, CoinTransactionType $type = CoinTransactionType::Bonus, string $code = 'credit', array $context = []): CoinTransaction
    {
        if ($amount < 0) {
            throw new \DomainException('Credit must be positive.');
        }

        return $this->move($user, $amount, $type, $code, $context);
    }

    public function debit(User|int $user, int $amount, string $code, array $context = []): CoinTransaction
    {
        if ($amount < 0) {
            throw new \DomainException('Debit must be positive.');
        }

        return $this->move($user, -$amount, CoinTransactionType::Debit, $code, $context);
    }

    public function refund(CoinTransaction $original, string $reason): CoinTransaction
    {
        if ($original->amount >= 0) {
            throw new \DomainException('Only debits can be refunded.');
        }
        if (CoinTransaction::where('reverses_transaction_id', $original->id)->exists()) {
            throw new \DomainException('Transaction already refunded.');
        }

        return $this->credit($original->user_id, abs($original->amount), CoinTransactionType::Refund, 'refund', ['reverses_transaction_id' => $original->id, 'description' => $reason]);
    }

    public function adjust(User|int $user, int $amount, string $reason, bool $credit = true): CoinTransaction
    {
        return $this->move($user, $credit ? abs($amount) : -abs($amount), $credit ? CoinTransactionType::AdminCredit : CoinTransactionType::AdminDebit, 'admin_adjustment', ['description' => $reason]);
    }

    private function move(User|int $user, int $amount, CoinTransactionType $type, string $code, array $context): CoinTransaction
    {
        return DB::transaction(function () use ($user, $amount, $type, $code, $context) {
            $id = $user instanceof User ? $user->id : $user;
            $w = Wallet::firstOrCreate(['user_id' => $id]);
            $w = Wallet::whereKey($w->id)->lockForUpdate()->firstOrFail();
            if (! empty($context['idempotency_key'])) {
                $old = CoinTransaction::where('idempotency_key', $context['idempotency_key'])->first();
                if ($old) {
                    return $old;
                }
            } $before = (int) $w->balance;
            $after = $before + $amount;
            if ($after < 0) {
                throw new InsufficientCoinsException(abs($amount), $before);
            }$w->update(['balance' => $after]);

            return CoinTransaction::create(['wallet_id' => $w->id, 'user_id' => $id, 'type' => $type, 'amount' => $amount, 'balance_before' => $before, 'balance_after' => $after, 'reference_type' => $context['reference_type'] ?? null, 'reference_id' => $context['reference_id'] ?? null, 'idempotency_key' => $context['idempotency_key'] ?? null, 'code' => $code, 'description' => $context['description'] ?? null, 'metadata' => $context['metadata'] ?? null, 'reverses_transaction_id' => $context['reverses_transaction_id'] ?? null, 'created_at' => now()]);
        });
    }
}
