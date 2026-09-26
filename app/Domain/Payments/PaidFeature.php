<?php

namespace App\Domain\Payments;

enum PaidFeature: string
{
    case DirectMessage = 'direct_message';
    case ChatAcceptance = 'chat_acceptance';
    case TelegramIdShare = 'telegram_id_share';
}
