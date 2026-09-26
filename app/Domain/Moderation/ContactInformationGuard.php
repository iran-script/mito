<?php

namespace App\Domain\Moderation;

class ContactInformationGuard
{
    public function blocked(string $text): bool
    {
        return (bool) preg_match('/(?:^|\s)@[a-z][a-z0-9_]{4,31}\b/i', $text)
            || (bool) preg_match('/\b(?:t\.me|telegram\.me)\/[a-z][a-z0-9_]{4,31}\b/i', $text)
            || (bool) preg_match('/\btg:\/\/[^\s]+/i', $text)
            || (bool) preg_match('/\b(?:my|send|share|contact)\s+(?:telegram\s+)?(?:id|username)\b[^\n]{0,80}@[a-z][a-z0-9_]{4,31}/i', $text);
    }
}
