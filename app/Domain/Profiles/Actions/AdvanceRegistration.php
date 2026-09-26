<?php

namespace App\Domain\Profiles\Actions;

use App\Domain\Payments\CoinTransactionType;
use App\Domain\Payments\WalletService;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Interest;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\Province;
use App\Domain\Profiles\RegistrationInput;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Profiles\RegistrationStep;
use App\Domain\Users\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdvanceRegistration
{
    public function __construct(private readonly ?WalletService $wallets = null) {}

    // Caller holds the user row lock and transaction, shared by all profile mutations.
    public function execute(User $user, Profile $profile, RegistrationState $state, RegistrationInput $input): ?string
    {
        Gate::forUser($user)->authorize('update', $profile);
        $text = trim($input->text ?? '');
        $choice = $input->choice;
        if ($profile->status === ProfileStatus::Active) {
            return null;
        }
        if ($text === '/start' || str_starts_with($text, '/start ')) {
            return null;
        }
        if ($text === '/back' || $choice === 'back') {
            if ($state->step === RegistrationStep::City && isset(($state->selection_context ?? [])['province_id'])) {
                $state->selection_context = ['page' => 0];

                return null;
            }
            $state->selection_context = null;
            $state->step = $state->step->previous();

            return null;
        }
        if ($state->step === RegistrationStep::Preview && str_starts_with($choice ?? '', 'edit_')) {
            $step = RegistrationStep::tryFrom(substr($choice, 5));
            if ($step && ! in_array($step, [RegistrationStep::Preview, RegistrationStep::Complete], true)) {
                $state->step = $step;
            }

            return null;
        }
        $context = $state->selection_context ?? [];
        if ($state->step === RegistrationStep::Age && preg_match('/^age_page_([0-7])$/D', $choice ?? '', $m)) {
            $state->selection_context = ['page' => (int) $m[1]];

            return null;
        }
        if ($state->step === RegistrationStep::City && preg_match('/^province_page_([0-9]+)$/D', $choice ?? '', $m)) {
            $state->selection_context = ['page' => min(100, (int) $m[1])];

            return null;
        }
        if ($state->step === RegistrationStep::City && preg_match('/^province_([1-9][0-9]*)$/D', $choice ?? '', $m)) {
            if (! Province::whereKey($m[1])->exists()) {
                return 'Choose a province using the buttons.';
            }
            $state->selection_context = ['province_id' => (int) $m[1], 'page' => 0];

            return null;
        }
        if ($state->step === RegistrationStep::City && preg_match('/^city_page_([1-9][0-9]*)_([0-9]+)$/D', $choice ?? '', $m)) {
            $state->selection_context = ['province_id' => (int) $m[1], 'page' => min(100, (int) $m[2])];

            return null;
        }
        $skip = $text === '/skip' || $choice === 'skip';
        switch ($state->step) {
            case RegistrationStep::Name:
                if (Validator::make(['name' => $text], ['name' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\p{L}\p{M}][\p{L}\p{M} \x{200C}\x{200D}\x{0027}-]*$/u']])->fails()) {
                    return 'Enter a name of 2-50 letters (spaces, apostrophes and hyphens allowed).';
                }
                $profile->display_name = $text;
                $state->step = RegistrationStep::Age;
                break;
            case RegistrationStep::Age:
                if (preg_match('/^age_([0-9]{2})$/D', $choice ?? '', $m) && (int) $m[1] >= 18 && (int) $m[1] <= 80) {
                    $profile->birth_date = CarbonImmutable::today()->subYearsNoOverflow((int) $m[1]);
                } elseif (Validator::make(['date' => $text], ['date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->subYearsNoOverflow(18)->toDateString(), 'after_or_equal:'.now()->subYearsNoOverflow(120)->toDateString()]])->passes()) {
                    // Keep old typed-date updates compatible; the normal UI only offers age buttons.
                    $profile->birth_date = CarbonImmutable::parse($text);
                } else {
                    return 'Choose an age from 18 to 80 using the buttons.';
                }
                $state->selection_context = null;
                $state->step = RegistrationStep::Gender;
                break;
            case RegistrationStep::Gender:
                $gender = Gender::tryFrom($choice ?? '');
                if (! $gender) {
                    return 'Choose a gender using the buttons.';
                }
                $profile->gender = $gender;
                $state->selection_context = null;
                $state->step = RegistrationStep::City;
                break;
            case RegistrationStep::City:
                if (! preg_match('/^city_([1-9][0-9]*)$/D', $choice ?? '', $m) || ! City::whereKey($m[1])->exists()) {
                    return 'Choose a listed city using the buttons.';
                }
                $profile->city_id = (int) $m[1];
                $state->selection_context = null;
                $state->step = RegistrationStep::Photo;
                break;
            case RegistrationStep::Photo:
                if (! $skip && ! $input->photo) {
                    return 'Send a photo, or choose Skip.';
                }
                $profile->photo_file_id = $skip ? null : $input->photo;
                $state->step = RegistrationStep::Voice;
                break;
            case RegistrationStep::Voice:
                if (! $skip && (! $input->voice || $input->voiceDuration === null || $input->voiceDuration > 120)) {
                    return 'Send a voice introduction of up to 120 seconds, or choose Skip.';
                }
                $profile->voice_file_id = $skip ? null : $input->voice;
                $profile->voice_duration = $skip ? null : $input->voiceDuration;
                $state->step = RegistrationStep::Interests;
                break;
            case RegistrationStep::Interests:
                if ($skip) {
                    $profile->interests()->detach();
                    $state->step = RegistrationStep::Preview;
                    break;
                }
                if ($choice === 'done') {
                    $state->step = RegistrationStep::Preview;
                    break;
                }
                if (! preg_match('/^interest_([1-9][0-9]*)$/D', $choice ?? '', $m) || ! Interest::whereKey($m[1])->exists()) {
                    return 'Select interests, then choose Done; or Skip.';
                }
                $profile->interests()->toggle([(int) $m[1]]);
                break;
            case RegistrationStep::Preview:
                if ($choice !== 'confirm') {
                    return 'Use Confirm or an Edit button.';
                }
                $valid = Validator::make([
                    'name' => $profile->display_name, 'birth_date' => $profile->birth_date?->toDateString(),
                    'gender' => $profile->gender?->value, 'city_id' => $profile->city_id,
                ], ['name' => 'required|string|min:2|max:50', 'birth_date' => 'required|date|before_or_equal:'.now()->subYearsNoOverflow(18)->toDateString(), 'gender' => ['required', Rule::enum(Gender::class)], 'city_id' => 'required|exists:cities,id']);
                if ($valid->fails()) {
                    $state->step = RegistrationStep::Name;

                    return 'Your profile is incomplete. Please review your details.';
                }
                $profile->status = ProfileStatus::Active;
                $profile->profile_completed_at = now();
                $user->registered_at ??= now();
                $user->save();
                $state->step = RegistrationStep::Complete;
                $bonus = (int) (DB::table('economy_settings')->where('key', 'signup_bonus')->value('value') ?? 0);
                if ($bonus > 0) {
                    ($this->wallets ?? app(WalletService::class))->credit($user, $bonus, CoinTransactionType::Bonus, 'signup_bonus', ['idempotency_key' => 'signup_bonus:'.$user->id]);
                }
                break;
            case RegistrationStep::Complete: break;
        }
        $profile->save();

        return null;
    }
}
