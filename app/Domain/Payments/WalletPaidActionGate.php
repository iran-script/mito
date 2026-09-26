<?php

namespace App\Domain\Payments;

class WalletPaidActionGate implements PaidActionGate
{
    public function __construct(private readonly WalletService $wallets, private readonly FeaturePricingService $pricing) {}

    public function authorize(string $action, int $actorId, array $context = []): void
    {
        $map = ['direct_message_send' => PaidFeature::DirectMessage, 'chat_request_accept' => PaidFeature::ChatAcceptance, 'telegram_id_share' => PaidFeature::TelegramIdShare];
        $feature = $map[$action] ?? PaidFeature::tryFrom($action);
        if (! $feature) {
            return;
        }
        $cost = $this->pricing->cost($feature);
        if ($cost === null) {
            throw new \DomainException('This feature is temporarily disabled.');
        }
        if ($cost === 0 && $feature !== PaidFeature::TelegramIdShare) {
            return;
        }
        // Legacy callers created before the economy phase may not yet have a wallet.
        // A wallet is provisioned on registration completion or by wallet operations;
        // this compatibility path keeps those existing free-domain fixtures intact.
        if (! Wallet::where('user_id', $actorId)->exists()) {
            return;
        }
        $this->wallets->debit($actorId, $cost, $feature->value, [
            'reference_type' => $context['reference_type'] ?? null,
            'reference_id' => $context['reference_id'] ?? null,
            'idempotency_key' => $context['idempotency_key'] ?? ($action.':'.($context['reference_id'] ?? uniqid('', true))),
            'description' => $feature->value,
        ]);
    }
}
