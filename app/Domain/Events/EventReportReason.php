<?php

namespace App\Domain\Events;

enum EventReportReason: string
{
    case InappropriateContent = 'inappropriate_content';
    case Spam = 'spam';
    case Scam = 'scam';
    case UnsafeEvent = 'unsafe_event';
    case FakeEvent = 'fake_event';
    case Other = 'other';
}
