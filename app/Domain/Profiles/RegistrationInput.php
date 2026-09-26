<?php

namespace App\Domain\Profiles;

final readonly class RegistrationInput
{
    public function __construct(
        public ?string $text = null,
        public ?string $choice = null,
        public ?string $photo = null,
        public ?string $voice = null,
        public ?int $voiceDuration = null,
    ) {}
}
