<?php

namespace App\Domain\Telegram;

use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\PublicProfile;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Profiles\RegistrationStep;
use App\Support\Presentation;

class RegistrationPresenter
{
    public function messages(Profile $profile, RegistrationState $state, ?string $error): array
    {
        if ($profile->status === ProfileStatus::Active) {
            return [['method' => 'sendMessage', 'parameters' => ['text' => __('Your profile is active. Welcome!')]]];
        }
        $context = $state->selection_context ?? [];
        $step = array_search($state->step, RegistrationStep::cases(), true) + 1;
        $text = match ($state->step) {
            RegistrationStep::Name => __('What should we call you? Send your first name.'),
            RegistrationStep::Age => __('How old are you? Choose your age below.'),
            RegistrationStep::Gender => __('Who are you?'),
            RegistrationStep::City => isset($context['province_id']) ? __('Choose your city.') : __('Choose your province.'),
            RegistrationStep::Photo => __('Send a profile photo if you like.'),
            RegistrationStep::Voice => __('Send a short voice introduction if you like.'),
            RegistrationStep::Interests => __('What are you into? Pick any that fit.'),
            RegistrationStep::Preview => __("Your profile\n").PublicProfile::fromProfile($profile)->text()."\n".__('Ready to show your profile?'),
            RegistrationStep::Complete => __('Your profile is active.'),
        };
        $rows = match ($state->step) {
            RegistrationStep::Age => Keyboard::ageReply((int) ($context['page'] ?? 0)),
            RegistrationStep::Gender => Keyboard::genderReply(),
            RegistrationStep::City => isset($context['province_id'])
                ? Keyboard::cityReply((int) $context['province_id'], (int) ($context['page'] ?? 0))
                : Keyboard::provinceReply((int) ($context['page'] ?? 0)),
            RegistrationStep::Photo, RegistrationStep::Voice => [[__('Skip'), __('Back')]],
            RegistrationStep::Interests => Keyboard::interestsReply($profile),
            RegistrationStep::Preview => Keyboard::confirmReply(),
            default => [],
        };
        $messages = [];
        if ($state->step === RegistrationStep::Preview) {
            if ($profile->photo_file_id) {
                $messages[] = ['method' => 'sendPhoto', 'parameters' => ['photo' => $profile->photo_file_id]];
            }
            if ($profile->voice_file_id) {
                $messages[] = ['method' => 'sendVoice', 'parameters' => ['voice' => $profile->voice_file_id]];
            }
        }
        $parameters = ['text' => ($error ? Presentation::error($error)."\n\n" : '').__('Step :step of 8', ['step' => min(8, $step)])."\n\n".$text];
        if ($rows) {
            $parameters['reply_markup'] = Keyboard::reply($rows);
        } elseif ($state->step === RegistrationStep::Name) {
            $parameters['reply_markup'] = ['remove_keyboard' => true];
        }
        $messages[] = ['method' => 'sendMessage', 'parameters' => $parameters];

        return $messages;
    }
}
