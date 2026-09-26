<?php

namespace App\Domain\Profiles;

enum RegistrationStep: string
{
    case Name = 'name';
    case Age = 'age';
    case Gender = 'gender';
    case City = 'city';
    case Photo = 'photo';
    case Voice = 'voice';
    case Interests = 'interests';
    case Preview = 'preview';
    case Complete = 'complete';

    public function previous(): self
    {
        $steps = self::cases();

        return $steps[max(0, array_search($this, $steps, true) - 1)];
    }
}
