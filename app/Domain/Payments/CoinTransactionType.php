<?php

namespace App\Domain\Payments;

enum CoinTransactionType: string
{
    case Purchase = 'purchase';
    case Bonus = 'bonus';
    case Debit = 'debit';
    case Refund = 'refund';
    case AdminCredit = 'admin_credit';
    case AdminDebit = 'admin_debit';
    case PromotionalCredit = 'promotional_credit';
    case TestCredit = 'test_credit';
    case ChatEngagementReward = 'chat_engagement_reward';
}
