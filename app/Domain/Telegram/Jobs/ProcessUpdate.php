<?php

namespace App\Domain\Telegram\Jobs;

use App\Domain\Profiles\Actions\AdvanceRegistration;
use App\Domain\Profiles\LocationService;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\RegistrationInput;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\DiscoveryInteraction;
use App\Domain\Telegram\IncomingUpdate;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\Keyboard;
use App\Domain\Telegram\RegistrationPresenter;
use App\Domain\Telegram\SocialInteraction;
use App\Domain\Users\MitoId;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use App\Support\Presentation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ProcessUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $updateId) {}

    public function backoff(): array
    {
        return [5, 15, 60, 120];
    }

    public function handle(AdvanceRegistration $registration, RegistrationPresenter $presenter, ?DiscoveryInteraction $discovery = null, ?LocationService $locations = null, ?SocialInteraction $social = null): void
    {
        $discovery ??= app(DiscoveryInteraction::class);
        $locations ??= app(LocationService::class);
        $social ??= app(SocialInteraction::class);
        DB::transaction(function () use ($registration, $presenter, $discovery, $locations, $social) {
            $envelope = DB::table('telegram_updates')->where('update_id', $this->updateId)->first();
            if (! $envelope || $envelope->processed_at) {
                return;
            }
            // Serialize new-user creation and all incoming updates for this sender.
            DB::select('SELECT pg_advisory_xact_lock(?)', [$envelope->telegram_user_id]);
            $pending = DB::table('telegram_updates')->where('telegram_user_id', $envelope->telegram_user_id)->whereNull('processed_at')->orderBy('update_id')->limit(100)->lockForUpdate()->get();
            foreach ($pending as $row) {
                $update = new IncomingUpdate(json_decode(Crypt::decryptString($row->payload), true, 512, JSON_THROW_ON_ERROR));
                $sender = $update->sender();
                $user = User::firstOrCreate(['telegram_user_id' => $update->userId()]);
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $user->fill([
                    'telegram_username' => $sender['username'] ?? null, 'telegram_first_name' => $sender['first_name'],
                    'telegram_last_name' => $sender['last_name'] ?? null, 'telegram_language_code' => $sender['language_code'] ?? null,
                    'is_bot' => $sender['is_bot'], 'last_activity_at' => now(),
                ])->save();
                $messages = [];
                if ($update->callbackId()) {
                    $messages[] = ['method' => 'answerCallbackQuery', 'parameters' => ['callback_query_id' => $update->callbackId()]];
                }
                if ($user->status !== UserStatus::Active) {
                    $messages[] = ['method' => 'sendMessage', 'parameters' => ['text' => __('Your account is unavailable.')]];
                } else {
                    try {
                        $profile = Profile::firstOrCreate(['user_id' => $user->id]);
                        $state = RegistrationState::firstOrCreate(['user_id' => $user->id]);
                        $interaction = InteractionState::firstOrCreate(['user_id' => $user->id]);
                        $input = $update->input(null);
                        $replyText = trim($input->text ?? '');
                        $homeAction = $update->callback() === null ? Keyboard::homeAction($replyText) : null;
                        $searchAction = $update->callback() === null ? Keyboard::searchAction($replyText, $interaction->mode) : null;
                        $eventAction = $update->callback() === null && $interaction->mode === 'event_create'
                            ? Keyboard::eventAction($replyText, $interaction->event_context ?? []) : null;
                        $error = null;
                        $choice = null;
                        $registrationCompleted = false;
                        if ($update->callback() !== null && str_starts_with($update->callback(), 'r:')) {
                            [, $revision, $choice] = explode(':', $update->callback(), 3);
                            $error = (int) $revision !== $state->revision ? __('This button has expired. Use the latest buttons below.') : $registration->execute($user, $profile, $state, $update->input($choice));
                            $registrationCompleted = $profile->status === ProfileStatus::Active;
                        } elseif ($profile->status === ProfileStatus::Active && Presentation::input(trim($input->text ?? '')) === '/start') {
                            $interaction->resetNavigation();
                            DB::table('matchmaking_searches')->where('user_id', $user->id)->where('status', 'waiting')
                                ->update(['status' => 'cancelled', 'updated_at' => now()]);
                            $messages = array_merge($messages, $discovery->menu($state));
                        } elseif ($profile->status === ProfileStatus::Active && $update->callback() === null && MitoId::looksLike($replyText)) {
                            $messages = array_merge($messages, $discovery->lookupProfile($user, $replyText, $state));
                        } elseif ($profile->status === ProfileStatus::Active && $homeAction !== null) {
                            $interaction->resetNavigation();
                            DB::table('matchmaking_searches')->where('user_id', $user->id)->where('status', 'waiting')
                                ->update(['status' => 'cancelled', 'updated_at' => now()]);
                            [$scope, $action] = $homeAction;
                            $replyUpdate = $update->asAction($scope, $scope === 's' ? $interaction->revision : $state->revision, $action);
                            $messages = array_merge($messages, $scope === 's'
                                ? $social->handle($user, $replyUpdate, $interaction)
                                : $discovery->handle($user, $replyUpdate, $state));
                        } elseif ($profile->status === ProfileStatus::Active && $searchAction !== null) {
                            $messages = array_merge($messages, $discovery->handle(
                                $user, $update->asAction('d', $state->revision, $searchAction), $state,
                            ));
                        } elseif ($profile->status === ProfileStatus::Active && $eventAction !== null) {
                            $messages = array_merge($messages, $discovery->handle(
                                $user, $update->asAction('d', $state->revision, $eventAction), $state,
                            ));
                        } elseif ($profile->status === ProfileStatus::Active && $update->callback() === null
                            && $replyText === __('Back') && in_array($interaction->mode, ['chat', 'direct', 'direct_compose', 'direct_review'], true)) {
                            $messages = array_merge($messages, $social->handle(
                                $user, $update->asAction('s', $interaction->revision, 'back'), $interaction,
                            ));
                        } elseif ($update->location()) {
                            $locations->update($user, (float) $update->location()['latitude'], (float) $update->location()['longitude']);
                            $messages[] = ['method' => 'sendMessage', 'parameters' => ['text' => __('Your location was updated. Exact coordinates stay private.')]];
                        } elseif ($profile->status === ProfileStatus::Active && preg_match('/^d:[0-9]+:(?:game_|games$)/', $update->callback() ?? '')) {
                            // A game notification must remain actionable while another chat is open.
                            $messages = array_merge($messages, $discovery->handle($user, $update, $state));
                        } elseif ($profile->status === ProfileStatus::Active && ($update->callback() !== null && (str_starts_with($update->callback(), 's:') || str_starts_with($update->callback(), 'n:')) || Presentation::input(trim($input->text ?? '')) === 'Chats' || in_array($interaction->mode, ['chat', 'direct', 'direct_compose', 'direct_review', 'request_review'], true))) {
                            $messages = array_merge($messages, $social->handle($user, $update, $interaction));
                            $interaction->increment('revision');
                        } elseif ($profile->status === ProfileStatus::Active) {
                            $messages = array_merge($messages, $discovery->handle($user, $update, $state));
                        } else {
                            $replyChoice = $update->callback() === null
                                ? Keyboard::registrationChoice(trim($input->text ?? ''), $state)
                                : null;
                            $registrationInput = $replyChoice === null ? $input : new RegistrationInput(
                                $input->text, $replyChoice, $input->photo, $input->voice, $input->voiceDuration,
                            );
                            $error = $registration->execute($user, $profile, $state, $registrationInput);
                            $registrationCompleted = $profile->status === ProfileStatus::Active;
                        }
                        $state->revision++;
                        $state->save();
                        $interaction->revision = $state->revision;
                        $interaction->save();
                        if ($profile->status !== ProfileStatus::Active && ! $update->location()) {
                            $messages = array_merge($messages, $presenter->messages($profile->fresh(), $state, $error));
                        }
                        if ($update->location()) {
                            $messages = array_merge($messages, $discovery->menu($state));
                        }
                        if ($registrationCompleted) {
                            $messages = array_merge($messages, $discovery->menu($state));
                        }
                    } catch (\DomainException $e) {
                        $messages[] = ['method' => 'sendMessage', 'parameters' => ['text' => Presentation::error($e->getMessage())]];
                    }
                }
                foreach ($messages as $sequence => $message) {
                    if ($message['method'] !== 'answerCallbackQuery') {
                        $message['parameters']['chat_id'] = $update->userId();
                    }
                    Keyboard::assertValidPayload($message);
                    $id = DB::table('telegram_outbox')->insertGetId([
                        'update_id' => $row->update_id, 'sequence' => $sequence,
                        'payload' => Crypt::encryptString(json_encode($message, JSON_THROW_ON_ERROR)),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DeliverMessage::dispatch($id)->onConnection('database')->onQueue('telegram-outbound');
                }
                // Retain only the deduplication key and operational metadata.
                DB::table('telegram_updates')->where('update_id', $row->update_id)->update(['payload' => null, 'processed_at' => now(), 'updated_at' => now()]);
            }
        }, 3);
    }
}
