<?php

namespace App\Domain\Moderation;

enum ReportReason: string
{
    case Harassment = 'harassment';
    case InappropriateContent = 'inappropriate_content';
    case FakeProfile = 'fake_profile';
    case Spam = 'spam';
    case Scam = 'scam';
    case RuleEvasion = 'rule_evasion';
    case Other = 'other';
}
