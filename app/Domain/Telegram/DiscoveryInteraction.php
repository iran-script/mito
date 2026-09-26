<?php

namespace App\Domain\Telegram;

use App\Domain\Chat\ConversationService;
use App\Domain\Contacts\ContactService;
use App\Domain\Discovery\DiscoveryResult;
use App\Domain\Discovery\DiscoveryService;
use App\Domain\Discovery\DiscoveryType;
use App\Domain\Discovery\DistanceRange;
use App\Domain\Events\Event;
use App\Domain\Events\EventCategory;
use App\Domain\Events\EventReportReason;
use App\Domain\Events\EventService;
use App\Domain\Games\GameService;
use App\Domain\Games\GameSession;
use App\Domain\Games\GameStatus;
use App\Domain\Games\GameType;
use App\Domain\Games\GuessInterestService;
use App\Domain\Games\GuessNumberService;
use App\Domain\Games\SpeedQuizService;
use App\Domain\Games\ThisOrThatService;
use App\Domain\Games\TwoTruthsService;
use App\Domain\Memberships\BulkChatRequestService;
use App\Domain\Memberships\BulkDirectMessageService;
use App\Domain\Memberships\GoldLimitsService;
use App\Domain\Memberships\GoldMembershipService;
use App\Domain\Moderation\ContactInformationGuard;
use App\Domain\Payments\FeaturePricingService;
use App\Domain\Payments\PaidFeature;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Interest;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\PublicProfile;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\Jobs\MatchmakingPoll;
use App\Domain\Users\BlockService;
use App\Domain\Users\MitoId;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use App\Support\Presentation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DiscoveryInteraction
{
    public function __construct(private readonly DiscoveryService $discovery, private readonly GoldMembershipService $gold, private readonly ContactService $contacts, private readonly BulkChatRequestService $bulkChats, private readonly BulkDirectMessageService $bulkDirects, private readonly ContactInformationGuard $guard, private readonly GoldLimitsService $limits, private readonly EventService $events, private readonly GameService $games, private readonly SpeedQuizService $speedQuiz, private readonly ThisOrThatService $thisOrThat, private readonly TwoTruthsService $twoTruths, private readonly GuessInterestService $guessInterest, private readonly GuessNumberService $guessNumber, private readonly ConversationService $conversations) {}

    public function handle(User $user, IncomingUpdate $update, RegistrationState $state): array
    {
        $interaction = InteractionState::firstOrCreate(['user_id' => $user->id]);
        if ($interaction->mode === 'event_create' && $update->location()) {
            $context = $interaction->event_context ?? [];
            $context['latitude'] = (float) $update->location()['latitude'];
            $context['longitude'] = (float) $update->location()['longitude'];
            $context['step'] = 'confirm';
            $interaction->update(['event_context' => $context]);

            return $this->message(__('Location saved. Review your event and publish.'), [[$this->button($state, __('Publish'), 'event_publish'), $this->button($state, __('Cancel'), 'event_cancel_wizard')]]);
        }
        $value = $this->value($update, $state);
        if ($update->callback() === null && str_starts_with($interaction->mode, 'profile_edit_')) {
            return $this->profileEditInput($user, $state, $interaction, $update);
        }
        if (in_array($value, ['games', 'menu', 'game_back', 'back'], true) && ($update->callback() !== null || in_array(Presentation::input(trim($update->input(null)->text ?? '')), ['/start', 'Back'], true))) {
            if (str_starts_with($interaction->mode ?? '', 'game_')) {
                $interaction->update(['mode' => 'menu', 'game_context' => null]);
            }
        }
        $hub = app(GameHubInteraction::class)->handle($user, $value);
        if ($hub !== null) {
            return $hub;
        }
        if (preg_match('/^game_open_([0-9]+)$/', $value ?? '', $m)) {
            try {
                return $this->openGame($user, $state, GameSession::findOrFail((int) $m[1]));
            } catch (\Throwable $e) {
                return $this->message(__('This game is unavailable.'));
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            }
        }
        if (preg_match('/^game_rps_answer_([0-9]+)_([0-9]+)_(rock|paper|scissors)$/', $value ?? '', $m)) {
            try {
                $session = GameSession::findOrFail((int) $m[1]);
                if ($session->status === GameStatus::Completed) {
                    return app(GameHubInteraction::class)->result($user, $session);
                }
                $result = $this->games->answer($user, $session, $m[3], null, (int) $m[2]);

                return $this->openGame($user, $state, $session->fresh());
            } catch (\Throwable $e) {
                return $this->message(__('This game is unavailable.'));
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            }
        }

        if (preg_match('/^game_tod_(truth|dare)_([0-9]+)$/', $value ?? '', $m)) {
            try {
                $session = GameSession::findOrFail((int) $m[2]);
                $this->games->assertPlayable($user, $session);

                return $this->truthOrDarePrompt($state, $session, $m[1]);
            } catch (\Throwable $e) {
                return $this->message(__('This game is unavailable.'));
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            }
        }

        if ($interaction->mode === 'event_create' && $update->callback() === null && Presentation::input(trim($update->input(null)->text ?? '')) !== '') {
            return $this->eventWizardText($user, $state, $interaction, trim($update->input(null)->text));
        }
        if ($interaction->mode === 'game_guess_number' && $update->callback() === null && Presentation::input(trim($update->input(null)->text ?? '')) !== '') {
            try {
                $session = GameSession::findOrFail((int) ($interaction->game_context['session_id'] ?? 0));
                $result = $this->guessNumber->guess($user, $session, Presentation::asciiDigits(trim($update->input(null)->text)), (string) $update->data['update_id'], (int) ($interaction->game_context['turn'] ?? 0));
                if ($result['duplicate']) {
                    return $this->message(__('That guess was already recorded.'));
                }
                if ($result['completed']) {
                    return $this->guessNumberScreen($user, $state, $session->fresh());
                }

                return $this->message(Presentation::label($result['hint']).'. '.($result['next_user_id'] === $user->id ? __('Your turn.') : __('Waiting for the other player.')));
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            }
        }
        if ($interaction->mode === 'game_guess_interest' && $update->callback() === null && Presentation::input(trim($update->input(null)->text ?? '')) !== '') {
            return $this->message(__('Choose one of the four inline interests.'));
        }
        if ($interaction->mode === 'game_two_truths' && $update->callback() === null && Presentation::input(trim($update->input(null)->text ?? '')) !== '') {
            try {
                $session = GameSession::findOrFail((int) ($interaction->game_context['session_id'] ?? 0));
                if ($session->current_round !== (int) ($interaction->game_context['round'] ?? 0)) {
                    throw new \DomainException('Open the current game to continue.');
                }
                $result = $this->twoTruths->addStatement($user, $session, trim($update->input(null)->text));
                if ($result['complete']) {
                    return $this->twoTruthsScreen($user, $state, $session->fresh());
                }

                return $this->message(__('Statement ').$result['count'].__(' of 3 saved. Enter the next statement:'));
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            } catch (\Throwable $e) {
                $interaction->update(['mode' => 'menu', 'game_context' => null]);

                return $this->message(__('This game is no longer available.'));
            }
        }
        if ($interaction->bulk_mode === 'direct_compose' && $update->callback() === null && Presentation::input(trim($update->input(null)->text ?? '')) !== '') {
            $text = trim($update->input(null)->text);
            if ($this->guard->blocked($text)) {
                return $this->message(__('Telegram IDs and contact links cannot be exchanged here. No coins were charged.'), [[$this->button($state, __('Cancel'), 'bulk_cancel')]]);
            }
            $ids = $interaction->bulk_selection ?? [];
            $eligible = User::whereIn('id', $ids)->where('status', 'active')->get();
            $price = app(FeaturePricingService::class)->cost(PaidFeature::DirectMessage) ?? 0;
            $cost = $price * $eligible->count();
            $interaction->update(['bulk_context' => ['text' => $text, 'key' => 'bulk:'.bin2hex(random_bytes(8))]]);

            return $this->message(__("Recipients: :v1\nCost per recipient: :v2 coins\nTotal: :v3 coins\nBalance: ", ['v1' => $eligible->count(), 'v2' => $price, 'v3' => $cost]).($user->wallet?->balance ?? 0), [[$this->button($state, __('Confirm Send'), 'bulk_confirm_direct'), $this->button($state, __('Cancel'), 'bulk_cancel')]]);
        }
        if ($value === null) {
            return $this->menu($state);
        }
        if (preg_match('/^game_tt_(accept|open)_([0-9]+)$/', $value, $m)) {
            try {
                $session = GameSession::findOrFail((int) $m[2]);
                if ($session->game_type !== GameType::TwoTruthsOneLie) {
                    throw new \DomainException('This game is unavailable.');
                }
                if ($m[1] === 'accept') {
                    $this->games->accept($user, $session);
                }

                return $this->twoTruthsScreen($user, $state, $session->fresh());
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            } catch (\Throwable $e) {
                return $this->message(__('This game is unavailable.'));
            }
        }
        if (preg_match('/^game_tt_(lie|guess)_([0-9]+)_([0-9]+)_([0-9]+)$/', $value, $m)) {
            try {
                $session = GameSession::findOrFail((int) $m[2]);
                $result = $m[1] === 'lie'
                    ? $this->twoTruths->selectLie($user, $session, (int) $m[4], (int) $m[3])
                    : $this->twoTruths->guess($user, $session, (int) $m[4], (int) $m[3]);
                if ($result['duplicate']) {
                    return $this->message(__('That choice was already recorded.'));
                }
                $messages = isset($result['lie']) ? $this->message(($result['correct'] ? __('Correct!') : __('Incorrect.')).__(' The lie was: ').$result['lie']) : [];

                return array_merge($messages, $this->twoTruthsScreen($user, $state, $session->fresh()));
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            } catch (\Throwable $e) {
                return $this->message(__('This game is unavailable.'));
            }
        }
        if (preg_match('/^game_(gi|gn)_(open|answer)_([0-9]+)(?:_([0-9]+)_([0-9]+))?$/', $value, $m)) {
            try {
                $session = GameSession::findOrFail((int) $m[3]);
                if ($m[1] === 'gi' && $m[2] === 'answer') {
                    if (! isset($m[4], $m[5])) {
                        throw new \DomainException('That option is invalid.');
                    }
                    $result = $this->guessInterest->answer($user, $session, (int) $m[5], (int) $m[4]);
                } elseif ($m[1] === 'gi') {
                    return $this->guessInterestScreen($user, $state, $session);
                } else {
                    return $this->guessNumberScreen($user, $state, $session);
                }
                if ($result['duplicate']) {
                    return $this->message(__('That answer was already recorded.'));
                }

                return array_merge($this->message($result['correct'] ? __('Correct!') : __('Incorrect.')), $this->guessInterestScreen($user, $state, $session->fresh()));
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            }
        }
        if ($value === 'more') {
            return $this->moreMenu($state);
        }
        if ($value === 'profile') {
            return $this->myProfile($user, $state, $interaction);
        }
        if (in_array($value, ['profile_edit_name', 'profile_edit_age', 'profile_edit_city', 'profile_edit_photo', 'profile_edit_voice'], true)) {
            return $this->startProfileEdit($user, $state, $interaction, $value);
        }
        if ($value === 'profile_edit_interests') {
            $interaction->update(['mode' => 'profile_edit_interests', 'direct_context' => []]);

            return $this->profileInterestEditor($user, $state);
        }
        if (preg_match('/^profile_interest_([1-9][0-9]*)$/D', $value ?? '', $m) && $interaction->mode === 'profile_edit_interests') {
            if (Interest::whereKey($m[1])->exists()) {
                $user->profile->interests()->toggle([(int) $m[1]]);
            }

            return $this->profileInterestEditor($user, $state);
        }
        if ($value === 'profile_interests_save' && $interaction->mode === 'profile_edit_interests') {
            $interaction->update(['mode' => 'menu', 'direct_context' => null]);

            return array_merge($this->message(__('Interests saved.')), $this->myProfile($user, $state, $interaction));
        }
        if ($value === 'notifications') {
            return $this->message(__('Your new messages and requests appear in Chats.'), Keyboard::navigation('d', $state->revision + 1, 'more'));
        }
        if ($value === 'settings') {
            return $this->message(__('Your profile and notification options are here when available.'), Keyboard::navigation('d', $state->revision + 1, 'more'));
        }
        if ($value === 'blocked') {
            $count = DB::table('user_blocks')->where('blocker_user_id', $user->id)->count();

            return $this->message(__('Blocked people: :count', ['count' => $count]), Keyboard::navigation('d', $state->revision + 1, 'more'));
        }
        if ($value === 'help') {
            return $this->message(__('Choose a menu button to explore. Use Back to return at any time.'), Keyboard::navigation('d', $state->revision + 1, 'more'));
        }
        if ($value === 'search_more') {
            return $this->message(__('More ways to find people'), [
                [$this->button($state, __('Anonymous search'), 'anonymous')],
                [$this->button($state, __('By distance'), 'distance')],
                [$this->button($state, __('Back'), 'search')],
            ]);
        }
        if ($value === 'match_cancel') {
            DB::table('matchmaking_searches')->where('user_id', $user->id)->where('status', 'waiting')->update(['status' => 'cancelled', 'updated_at' => now()]);

            return $this->message(__('Search cancelled.'), Keyboard::navigation('d', $state->revision + 1, 'search'));
        }
        if ($value === 'match_retry') {
            $gender = DB::table('matchmaking_searches')->where('user_id', $user->id)->value('gender');

            return $gender ? $this->anonymous($user, $state, $gender) : $this->genderMenu($state, 'anonymous');
        }
        if ($value === 'match_filters') {
            return $this->genderMenu($state, 'anonymous');
        }
        if (preg_match('/^profile_([1-9][0-9]*)_game_pick_(contacts|opponent)_(rock_paper_scissors|truth_or_dare)_([0-9]+)$/D', $value ?? '', $m)) {
            try {
                $type = GameType::tryFrom($m[3]);
                if (! $type || ! in_array($type, [GameType::RockPaperScissors, GameType::TruthOrDare], true)) {
                    return $this->message(__('That game is unavailable.'));
                }
                $this->games->invite($user, User::findOrFail((int) $m[1]), $type);

                return $this->message(__('Game invitation sent.'), [[$this->button($state, __('Back'), 'games')]]);
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            }
        }
        if (preg_match('/^profile_([1-9][0-9]*)(?:_(.+))?$/D', $value ?? '', $m)) {
            $profile = Profile::with(['city', 'interests', 'user'])->where('status', 'active')->where('user_id', (int) $m[1])->first();
            if (! $profile) {
                return $this->message(__('This user is unavailable.'));
            }
            $back = $m[2] ?? 'search';
            $distance = null;
            if (preg_match('/^page_distance_(05|510|1015|1520|020)_(male|female)_([1-9][0-9]*)$/D', $back, $parts)) {
                $range = DistanceRange::tryFrom($parts[1]);
                $gender = Gender::tryFrom($parts[2]);
                if ($range && $gender) {
                    foreach ($this->discovery->distance($user, $gender, $range, (int) $parts[3]) as $candidate) {
                        if ((int) $candidate->user_id === (int) $m[1]) {
                            $distance = isset($candidate->distance_km) ? (float) $candidate->distance_km : null;
                            break;
                        }
                    }
                }
            }

            return $this->card($user, $state, new DiscoveryResult($profile, $distance), $back);
        }
        if ($value === 'gold') {
            return $this->goldScreen($user, $state);
        }
        if ($value === 'games') {
            return $this->gamesMenu($user, $state);
        }
        if ($value === 'game_more') {
            return $this->gamesMenu($user, $state);
        }
        if ($value === 'game_stats_menu') {
            return $this->gameStatsMenu($state);
        }
        if (preg_match('/^game_select_(rock_paper_scissors|truth_or_dare)$/D', $value ?? '', $m)) {
            return $this->gameOpponentModeMenu($state, GameType::from($m[1]));
        }
        if ($value === 'game_back') {
            return $this->menu($state);
        }
        if ($value === 'game_opponent' || $value === 'game_random' || $value === 'game_contacts') {
            return $this->gameTypeMenu($state, $value);
        }
        if (preg_match('/^game_opponent_mode_(random|contacts)_(rock_paper_scissors|truth_or_dare)$/', $value ?? '', $m)) {
            $type = GameType::from($m[2]);
            if ($m[1] === 'contacts') {
                return $this->gameOpponents($user, $state, 'contacts', $type, 1);
            }

            return $this->message(__('👥 دوست داری با چه کسی بازی کنی؟'), [
                [$this->button($state, __('👩 دختر'), 'game_random_gender_'.$type->value.'_female')],
                [$this->button($state, __('👨 پسر'), 'game_random_gender_'.$type->value.'_male')],
                [$this->button($state, __('انصراف'), 'games')],
            ]);
        }
        if (preg_match('/^game_random_gender_(rock_paper_scissors|truth_or_dare)_(female|male)$/', $value ?? '', $m)) {
            return $this->randomGameQueue($user, $state, GameType::from($m[1]), $m[2]);
        }
        if ($value === 'game_match_cancel') {
            DB::table('game_matchmaking_queue')->where('user_id', $user->id)->where('status', 'waiting')->update(['status' => 'cancelled', 'updated_at' => now()]);

            return $this->gamesMenu($user, $state);
        }
        if ($value === 'game_match_retry') {
            $row = DB::table('game_matchmaking_queue')->where('user_id', $user->id)->whereIn('status', ['expired', 'cancelled'])->latest('updated_at')->first();
            if (! $row || ! GameType::tryFrom($row->game_type)) {
                return $this->gamesMenu($user, $state);
            }

            return $this->randomGameQueue($user, $state, GameType::from($row->game_type), (string) $row->desired_gender);
        }
        if (preg_match('/^game_type_(opponent|random|contacts)_([a-z_]+)$/', $value, $m)) {
            $type = GameType::tryFrom($m[2]);
            if (! $type || ! in_array($type, [GameType::RockPaperScissors, GameType::TruthOrDare], true)) {
                return $this->message(__('That game is unavailable.'));
            }
            if ($m[1] === 'contacts') {
                return $this->gameOpponents($user, $state, 'contacts', $type, 1);
            }
            if ($m[1] === 'random') {
                return $this->message(__('👥 دوست داری با چه کسی بازی کنی؟'), [
                    [$this->button($state, __('👩 دختر'), 'game_random_gender_'.$type->value.'_female')],
                    [$this->button($state, __('👨 پسر'), 'game_random_gender_'.$type->value.'_male')],
                    [$this->button($state, __('انصراف'), 'games')],
                ]);
            }

            return $this->gameOpponents($user, $state, 'opponent', $type, 1);
        }
        if (preg_match('/^game_pick_(opponent|contacts)_([a-z_]+)_([0-9]+)$/', $value, $m)) {
            $type = GameType::tryFrom($m[2]);

            return $type ? $this->gameOpponents($user, $state, $m[1], $type, (int) $m[3]) : $this->message(__('That game is unavailable.'));
        }
        if (preg_match('/^game_play_([a-z_]+)_([0-9]+)$/', $value, $m)) {
            try {
                $session = $this->games->invite($user, User::findOrFail((int) $m[2]), GameType::from($m[1]));

                return $this->message(__('Game invitation sent.'), [[$this->button($state, __('Back'), 'games')]]);
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            }
        }
        if (preg_match('/^game_reject_([0-9]+)$/', $value, $m)) {
            $session = GameSession::findOrFail((int) $m[1]);
            if ((int) ($session->state['opponent_id'] ?? 0) !== $user->id) {
                throw new \DomainException('فقط دعوت‌شده می‌تواند درخواست بازی را رد کند.');
            }
            if ($session->status === GameStatus::Waiting) {
                $session->update(['status' => GameStatus::Cancelled]);
                app(SocialNotificationService::class)->queue($session->creator, 'game_rejected', $session->id, __('درخواست بازی شما رد شد.'), 'game_rejected:'.$session->id);
            }
            $response = $this->message(__('درخواست بازی رد شد.'));
            $callbackMessage = $update->data['callback_query']['message'] ?? null;
            if ($callbackMessage && isset($callbackMessage['message_id'], $callbackMessage['chat']['id'])) {
                $response[] = ['method' => 'editMessageReplyMarkup', 'parameters' => ['chat_id' => $callbackMessage['chat']['id'], 'message_id' => $callbackMessage['message_id'], 'reply_markup' => ['inline_keyboard' => []]]];
            }

            return $response;
        }
        if (preg_match('/^game_accept_([0-9]+)$/', $value, $m)) {
            try {
                $this->games->accept($user, GameSession::findOrFail((int) $m[1]));
                $response = $this->openGame($user, $state, GameSession::findOrFail((int) $m[1]));
                $callbackMessage = $update->data['callback_query']['message'] ?? null;
                if ($callbackMessage && isset($callbackMessage['message_id'], $callbackMessage['chat']['id'])) {
                    $response[] = ['method' => 'editMessageReplyMarkup', 'parameters' => ['chat_id' => $callbackMessage['chat']['id'], 'message_id' => $callbackMessage['message_id'], 'reply_markup' => ['inline_keyboard' => []]]];
                }

                return $response;
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            }
        }
        if (preg_match('/^game_speed_answer_([0-9]+)_([0-9]+)_([0-9]+)$/', $value, $m)) {
            try {
                $session = GameSession::findOrFail((int) $m[1]);
                $options = $this->speedQuiz->question($session)['options'];
                $option = $options[(int) $m[3]] ?? '';
                $result = $this->speedQuiz->answer($user, $session, $option, null, (int) $m[2]);
                if (! $result['resolved']) {
                    return $this->message(__('Your answer was recorded. Waiting for the other player.'));
                } if ($result['completed']) {
                    return app(GameHubInteraction::class)->result($user, $session->fresh());
                }

                return $this->speedQuizScreen($state, $session->fresh());
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            }
        }
        if (preg_match('/^game_this_answer_([0-9]+)_([0-9]+)_([A-Za-z0-9]+)$/', $value, $m)) {
            try {
                $session = GameSession::findOrFail((int) $m[1]);
                $question = $this->thisOrThat->question($session);
                $option = $question['options'][(int) $m[3]] ?? '';
                $result = $this->thisOrThat->answer($user, $session, $option, (int) $m[2]);
                if (! $result['resolved']) {
                    return $this->message(__('Your answer was recorded. Waiting for the other player.'));
                }
                if ($result['completed']) {
                    return app(GameHubInteraction::class)->result($user, $session->fresh());
                }

                return $this->thisOrThatScreen($state, $session->fresh());
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            }
        }
        if (preg_match('/^game_answer_([0-9]+)_([a-zA-Z0-9]+)$/', $value, $m)) {
            try {
                $r = $this->games->answer($user, GameSession::findOrFail((int) $m[1]), $m[2]);

                return $r['completed'] ? app(GameHubInteraction::class)->result($user, GameSession::findOrFail((int) $m[1])) : $this->message($r['duplicate'] ? __('Answer already recorded.') : __('Answer recorded.'));
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            }
        }
        if ($value === 'events') {
            $interaction->update(['mode' => 'events', 'event_context' => null]);

            return $this->eventsMenu($user, $state);
        }
        if ($value === 'event_create') {
            $interaction->update(['mode' => 'event_create', 'event_context' => ['step' => 'title']]);

            return $this->message(__('Event step 1 of 8')."\n".__('What should we call your event?'), Keyboard::navigation('d', $state->revision + 1, 'events'));
        }
        if ($value === 'event_step_back' && $interaction->mode === 'event_create') {
            return $this->eventBack($state, $interaction);
        }
        if (preg_match('/^event_day_page_([0-9]+)$/D', $value ?? '', $m) && $interaction->mode === 'event_create') {
            return $this->eventDate($state, min(3, (int) $m[1]));
        }
        if (preg_match('/^event_day_([0-9]{8})$/D', $value ?? '', $m) && $interaction->mode === 'event_create') {
            try {
                $day = CarbonImmutable::createFromFormat('!Ymd', $m[1], config('presentation.timezone'));
            } catch (\Throwable $e) {
                $day = null;
            }
            if (! $day || $day->lt(CarbonImmutable::today(config('presentation.timezone'))) || $day->gt(CarbonImmutable::today(config('presentation.timezone'))->addDays(30))) {
                return $this->eventDate($state);
            }
            $context = $interaction->event_context ?? [];
            $context['day'] = $day->format('Y-m-d');
            $context['step'] = 'time';
            $interaction->update(['event_context' => $context]);

            return $this->eventTime($state);
        }
        if (preg_match('/^event_time_([0-2][0-9][0-5][0-9])$/D', $value ?? '', $m) && $interaction->mode === 'event_create') {
            return $this->selectEventTime($state, $interaction, substr($m[1], 0, 2).':'.substr($m[1], 2));
        }
        if ($value === 'event_time_custom' && $interaction->mode === 'event_create') {
            $context = $interaction->event_context ?? [];
            $context['step'] = 'time_custom';
            $interaction->update(['event_context' => $context]);

            return $this->message(__('Event step 5 of 8')."\n".__('What time? Send it like 19:30.'), Keyboard::navigation('d', $state->revision + 1, 'event_step_back', 'event_cancel_wizard'));
        }
        if (preg_match('/^event_capacity_(0|[1-9][0-9]*)$/D', $value ?? '', $m) && $interaction->mode === 'event_create') {
            $context = $interaction->event_context ?? [];
            $context['capacity'] = (int) $m[1] ?: null;
            $context['step'] = 'location';
            $interaction->update(['event_context' => $context]);

            return $this->eventLocation($state);
        }
        if ($value === 'event_capacity_custom' && $interaction->mode === 'event_create') {
            $context = $interaction->event_context ?? [];
            $context['step'] = 'capacity_custom';
            $interaction->update(['event_context' => $context]);

            return $this->message(__('Event step 6 of 8')."\n".__('How many people? Send a number.'), Keyboard::navigation('d', $state->revision + 1, 'event_step_back', 'event_cancel_wizard'));
        }
        if ($value === 'event_category') {
            if ($interaction->mode !== 'event_create') {
                return $this->message(__('That event action is no longer available.'));
            }
            $rows = EventCategory::orderBy('name')->get()->map(fn ($c) => [$this->button($state, Presentation::label($c->name), 'event_wizard_category_'.$c->id)])->all();
            $rows[] = [$this->button($state, __('Cancel'), 'event_cancel_wizard')];

            return $this->message(__('Event step 2 of 8')."\n".__('Choose a category.'), $rows);
        }
        if ($value === 'event_location_city' || $value === 'event_location_skip') {
            if ($interaction->mode !== 'event_create' || ! isset($interaction->event_context['title'])) {
                return $this->message(__('That event draft is no longer available.'));
            }
            $context = $interaction->event_context ?? [];
            $context['city_id'] = $user->profile?->city_id;
            $context['step'] = 'confirm';
            $interaction->update(['event_context' => $context]);

            return $this->message(__('Event step 8 of 8')."\n".$context['title']."\n".$this->eventDateLabel($context['starts_at'])."\n".__('Capacity: :count', ['count' => $context['capacity'] ?? __('Unlimited')]), [
                [$this->button($state, __('Publish'), 'event_publish')],
                [$this->button($state, __('Back'), 'event_step_back'), $this->button($state, __('Cancel'), 'event_cancel_wizard')],
            ]);
        }
        if ($value === 'event_edit' && $interaction->mode === 'event_create') {
            $context = $interaction->event_context ?? [];
            $context['step'] = 'title';
            $interaction->update(['event_context' => $context]);

            return $this->message(__('Enter a new event title:'));
        }
        if (preg_match('/^event_wizard_category_([0-9]+)$/', $value, $m)) {
            $category = EventCategory::find((int) $m[1]);
            if (! $category) {
                return $this->message(__('That category is no longer available.'));
            }
            $context = $interaction->event_context ?? [];
            $context['category_id'] = $category->id;
            $context['step'] = 'description';
            $interaction->update(['event_context' => $context]);

            return $this->message(__('Event step 3 of 8')."\n".__('Tell us a little about it. Send /skip to leave this blank.'), Keyboard::navigation('d', $state->revision + 1, 'event_step_back', 'event_cancel_wizard'));
        }
        if ($value === 'event_cancel_wizard' || $value === 'events_back') {
            $interaction->update(['mode' => 'menu', 'event_context' => null]);

            return $value === 'events_back' ? $this->eventsMenu($user, $state) : $this->menu($state);
        }
        if ($value === 'event_nearby') {
            return $this->message(__('Choose an event distance range.'), [[$this->button($state, __('0-5 km'), 'event_nearby_05'), $this->button($state, __('5-10 km'), 'event_nearby_510')], [$this->button($state, __('10-15 km'), 'event_nearby_1015'), $this->button($state, __('15-20 km'), 'event_nearby_1520')], [$this->button($state, __('0-20 km'), 'event_nearby_020')], [$this->button($state, __('Back'), 'events')]]);
        }
        if (preg_match('/^event_nearby_(05|510|1015|1520|020)$/', $value, $m)) {
            if (! $user->profile?->latitude || ! $user->profile?->longitude) {
                return $this->message(__('Share your location first to find nearby events.'), [[$this->button($state, __('Back'), 'events')]]);
            }
            $bounds = ['05' => [0, 5], '510' => [5, 10], '1015' => [10, 15], '1520' => [15, 20], '020' => [0, 20]][$m[1]];
            $results = $this->events->nearby($user, $bounds[0], $bounds[1], 1);

            return $this->eventCards($state, $results, 'event_nearby_'.$m[1]);
        }
        if (in_array($value, ['event_today', 'event_week', 'event_new', 'event_mine', 'event_joined'], true)) {
            return $this->eventCards($state, $this->events->listing($user, match ($value) {
                'event_today' => 'today', 'event_week' => 'week', 'event_mine' => 'mine', 'event_joined' => 'joined', default => 'new'
            }), str_replace('event_', '', $value));
        }
        if ($value === 'event_categories') {
            $rows = EventCategory::orderBy('name')->get()->map(fn ($c) => [$this->button($state, Presentation::label($c->name), 'event_category_'.$c->id)])->all();
            $rows[] = [$this->button($state, __('Back'), 'events')];

            return $this->message(__('Choose a category.'), $rows);
        }
        if (preg_match('/^event_category_([0-9]+)$/', $value, $m)) {
            return $this->eventCards($state, $this->events->listing($user, 'category', 1, (int) $m[1]), 'event_category_'.$m[1]);
        }
        if (preg_match('/^event_page_(.+)_([1-9][0-9]*)$/', $value, $m)) {
            $filter = $m[1];
            $page = (int) $m[2];
            if (str_starts_with($filter, 'nearby_')) {
                $range = substr($filter, 8);
                $bounds = ['05' => [0, 5], '510' => [5, 10], '1015' => [10, 15], '1520' => [15, 20], '020' => [0, 20]][$range] ?? null;

                return $bounds ? $this->eventCards($state, $this->events->nearby($user, $bounds[0], $bounds[1], $page), $filter) : $this->message(__('That page is no longer available.'));
            }
            if (str_starts_with($filter, 'category_')) {
                return $this->eventCards($state, $this->events->listing($user, 'category', $page, (int) substr($filter, 9)), $filter);
            }

            return $this->eventCards($state, $this->events->listing($user, $filter, $page), $filter);
        }
        if (str_starts_with($value, 'event_join_')) {
            try {
                $this->events->join($user, Event::findOrFail((int) substr($value, 11)));
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()), [[$this->button($state, __('Back'), 'events')]]);
            }

            return $this->message(__('Joined successfully.'), [[$this->button($state, __('Back'), 'events')]]);
        }
        if (str_starts_with($value, 'event_leave_')) {
            $this->events->leave($user, Event::findOrFail((int) substr($value, 12)));

            return $this->message(__('You left the event.'), [[$this->button($state, __('Back'), 'events')]]);
        }
        if (str_starts_with($value, 'event_cancel_')) {
            try {
                $this->events->cancel($user, Event::findOrFail((int) substr($value, 13)));
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()), [[$this->button($state, __('Back'), 'events')]]);
            }

            return $this->message(__('Event cancelled.'), [[$this->button($state, __('Back'), 'events')]]);
        }
        if (str_starts_with($value, 'event_report_') && ! str_starts_with($value, 'event_report_reason_')) {
            $id = (int) substr($value, 13);

            return $this->message(__('Choose a report reason.'), array_map(fn ($reason) => [$this->button($state, Presentation::label($reason->value), 'event_report_reason_'.$id.'_'.$reason->value)], EventReportReason::cases()));
        }
        if (preg_match('/^event_report_reason_([0-9]+)_([a-z_]+)$/', $value, $m)) {
            $reason = EventReportReason::tryFrom($m[2]);
            if (! $reason) {
                return $this->message(__('That report reason is invalid.'));
            }
            $this->events->report($user, Event::findOrFail((int) $m[1]), $reason);

            return $this->message(__('Event report submitted.'), [[$this->button($state, __('Back'), 'events')]]);
        }
        if (str_starts_with($value, 'event_view_')) {
            return $this->eventDetail($user, $state, Event::with(['category', 'city', 'creator.profile'])->findOrFail((int) substr($value, 11)));
        }
        if (preg_match('/^event_participants_([0-9]+)$/', $value, $m)) {
            $event = Event::findOrFail((int) $m[1]);
            $page = $this->events->participants($user, $event);
            $rows = [];
            foreach ($page as $participant) {
                $rows[] = [$this->button($state, $participant->profile?->display_name ?? __('Participant'), 'profile_'.$participant->id.'_event_participants_'.$event->id)];
            }
            $rows[] = [$this->button($state, __('Back'), 'event_view_'.$event->id)];

            return $this->message(__('Participants'), $rows);
        }
        if ($value === 'event_publish') {
            if ($interaction->mode !== 'event_create' || ! isset($interaction->event_context['title'])) {
                return $this->message(__('That event draft is no longer available.'));
            }

            return $this->publishEvent($user, $state, $interaction);
        }
        if ($value === 'gold_benefits') {
            return $this->message(__("Gold benefits\nGold badge\nPriority search placement\nBulk selection\nBulk chat requests\nBulk direct messages\nHigher limits"), [[$this->button($state, __('Back'), 'gold')]]);
        }
        if ($value === 'gold_usage') {
            $chat = (int) DB::table('gold_usage_events')->where('user_id', $user->id)->where('action', 'bulk_chat_request')->where('occurred_at', '>=', now()->startOfDay())->sum('recipient_count');
            $direct = (int) DB::table('gold_usage_events')->where('user_id', $user->id)->where('action', 'bulk_direct_message')->where('occurred_at', '>=', now()->startOfDay())->sum('recipient_count');

            return $this->message(__('Bulk Chat Requests Today: :v1 / ', ['v1' => $chat]).$this->limits->dailyChat($user).__("\nBulk Direct Recipients Today: :v1 / ", ['v1' => $direct]).$this->limits->dailyDirect($user).__("\nMax Recipients Per Action: ").$this->limits->maxRecipients(), [[$this->button($state, __('Back'), 'gold')]]);
        }
        if ($value === 'contacts') {
            return $this->contactsScreen($user, $state);
        }
        if (preg_match('/^add_contact_([1-9][0-9]*)$/D', $value ?? '', $m)) {
            try {
                $this->contacts->add($user, User::findOrFail((int) $m[1]));

                return $this->message(__('Added to contacts.'), Keyboard::navigation('d', $state->revision + 1, 'contacts'));
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            }
        }
        if (preg_match('/^profile_voice_([1-9][0-9]*)$/D', $value ?? '', $m)) {
            $profile = Profile::where('user_id', (int) $m[1])->first();

            return $profile?->voice_file_id
                ? [['method' => 'sendVoice', 'parameters' => ['voice' => $profile->voice_file_id]]]
                : $this->message(__('No voice introduction is available.'));
        }
        if (preg_match('/^profile_more_([1-9][0-9]*)$/D', $value ?? '', $m)) {
            $id = (int) $m[1];

            return $this->message(__('More profile actions'), [
                [Keyboard::button('s', $state->revision + 1, __('Block'), 'block_'.$id)],
                [Keyboard::button('s', $state->revision + 1, __('Report'), 'report_'.$id)],
                [$this->button($state, __('Back'), 'profile_'.$id)],
            ]);
        }
        if (preg_match('/^profile_([1-9][0-9]*)_game_pick_(contacts|opponent)_(rock_paper_scissors|truth_or_dare)_([0-9]+)$/D', $value ?? '', $m)) {
            try {
                $type = GameType::tryFrom($m[3]);
                if (! $type || ! in_array($type, [GameType::RockPaperScissors, GameType::TruthOrDare], true)) {
                    return $this->message(__('That game is unavailable.'));
                }
                $this->games->invite($user, User::findOrFail((int) $m[1]), $type);

                return $this->message(__('Game invitation sent.'), [[$this->button($state, __('Back'), 'games')]]);
            } catch (\Throwable $e) {
                return $this->message(Presentation::error($e->getMessage()));
            }
        }
        if (preg_match('/^profile_game_([1-9][0-9]*)$/D', $value ?? '', $m)) {
            $rows = [
                [$this->button($state, __('✊ سنگ، کاغذ، قیچی'), 'game_play_rock_paper_scissors_'.$m[1])],
                [$this->button($state, __('😈 جرأت یا حقیقت'), 'game_play_truth_or_dare_'.$m[1])],
                [$this->button($state, __('انصراف'), 'profile_'.$m[1])],
            ];

            return $this->message(__('🎮 یک بازی انتخاب کن:'), $rows);
        }
        if (str_starts_with($value, 'contact_')) {
            $contact = User::with(['profile.city', 'profile.interests'])->findOrFail((int) substr($value, 8));

            return $this->card($user, $state, new DiscoveryResult($contact->profile), 'contacts');
        }
        if (str_starts_with($value, 'remove_contact_')) {
            $removed = $this->contacts->removeById($user, (int) substr($value, 15));

            return $this->message($removed ? __('Contact removed.') : __('That contact is no longer in your contacts.'), [[$this->button($state, __('Back to Contacts'), 'contacts')]]);
        }
        if ($value === 'bulk_start') {
            if (! $this->gold->isGold($user)) {
                return $this->message(__('Gold membership is required.'));
            } $interaction->update(['bulk_mode' => 'select', 'bulk_selection' => []]);

            return $this->message(__('Bulk Select Mode. Choose Select for Bulk on profiles, then return here to send.'));
        }
        if (str_starts_with($value, 'bulk_select_')) {
            return $this->toggleBulk($user, $state, $interaction, (int) substr($value, 12));
        }
        if ($value === 'bulk_clear' || $value === 'bulk_cancel') {
            $interaction->update(['bulk_mode' => null, 'bulk_selection' => null, 'bulk_context' => null]);

            return $this->menu($state);
        }
        if ($value === 'bulk_chat') {
            return $this->executeBulkChat($user, $state, $interaction);
        }
        if ($value === 'bulk_direct') {
            if (! $this->gold->isGold($user)) {
                return $this->message(__('Gold membership is required.'));
            } $interaction->update(['bulk_mode' => 'direct_compose']);

            return $this->message(__('Type one message for the selected users, or press Cancel.'));
        }
        if ($value === 'bulk_confirm_direct') {
            return $this->executeBulkDirect($user, $state, $interaction);
        }
        [$action, $argument] = array_pad(explode('_', $value, 2), 2, null);
        if ($value === 'menu' || $value === 'back') {
            $interaction->update(['mode' => 'menu']);

            return $this->menu($state);
        }
        if ($value === 'search') {
            $interaction->update(['mode' => 'search']);

            return $this->searchMenu($state);
        }
        if ($value === 'search_male' || $value === 'search_female') {
            $interaction->update(['mode' => $value]);

            return $this->searchMenu($state);
        }
        if ($value === 'anonymous') {
            return $this->genderMenu($state, 'anonymous');
        }
        if ($value === 'nearby') {
            return $this->genderMenu($state, 'nearby');
        }
        if ($value === 'interest') {
            return $this->interestChoices($state);
        }
        if ($value === 'city' || $value === 'age' || $value === 'new' || $value === 'distance') {
            return $this->genderMenu($state, $value);
        }
        if ($action === 'anonymous' && $argument) {
            return $this->anonymous($user, $state, $argument);
        }
        if (in_array($action, ['nearby', 'distance'], true) && in_array($argument, ['male', 'female'], true)) {
            return $this->distanceMenu($state, $action, $argument);
        }
        if (in_array($action, ['city', 'age', 'new'], true) && $argument) {
            return $this->list($user, $state, DiscoveryType::from($action), $argument, null, 1);
        }
        if ($action === 'interest' && $argument && ! str_contains($argument, '_') && ctype_digit($argument)) {
            if (in_array($interaction->mode, ['search_male', 'search_female'], true)) {
                return $this->list($user, $state, DiscoveryType::Interest, substr($interaction->mode, 7), (int) $argument, 1);
            }

            return $this->genderMenu($state, 'interest_'.$argument);
        }
        if (str_starts_with($value, 'interest_')) {
            $parts = explode('_', $value);
            if (count($parts) === 3) {
                return $this->list($user, $state, DiscoveryType::Interest, $parts[2], (int) $parts[1], 1);
            }
        }
        if (str_starts_with($value, 'distance_')) {
            $parts = explode('_', $value);
            if (count($parts) === 3) {
                return $this->distance($user, $state, $parts[2], $parts[1], 1);
            }
        }
        if (str_starts_with($value, 'page_')) {
            $parts = explode('_', $value);
            if (count($parts) === 4) {
                return $this->list($user, $state, DiscoveryType::from($parts[1]), $parts[2], null, (int) $parts[3]);
            }
            if (count($parts) === 5 && $parts[1] === 'interest') {
                return $this->list($user, $state, DiscoveryType::Interest, $parts[3], (int) $parts[2], (int) $parts[4]);
            }
            if (count($parts) === 5 && $parts[1] === 'distance') {
                return $this->distance($user, $state, $parts[3], $parts[2], (int) $parts[4]);
            }
        }

        return [['method' => 'sendMessage', 'parameters' => ['text' => __('That discovery option is no longer available.')]]];
    }

    public function menu(RegistrationState $state): array
    {
        return [['method' => 'sendMessage', 'parameters' => [
            'text' => __('Welcome to Mito. What would you like to do?'),
            'reply_markup' => Keyboard::reply(Keyboard::homeReply(), true),
        ]]];
    }

    private function moreMenu(RegistrationState $state): array
    {
        return $this->message(__('More'), [
            [$this->button($state, __('Gold membership'), 'gold')],
            [$this->button($state, __('Notifications'), 'notifications'), $this->button($state, __('Settings'), 'settings')],
            [$this->button($state, __('Blocked people'), 'blocked'), $this->button($state, __('Help'), 'help')],
            [$this->button($state, __('Back'), 'menu')],
        ]);
    }

    private function gamesMenu(User $user, RegistrationState $state): array
    {
        return $this->message(__('Choose one of these games:'), [
            [$this->button($state, __('RPS'), 'game_select_rock_paper_scissors')],
            [$this->button($state, __('Truth or Dare'), 'game_select_truth_or_dare')],
            [$this->button($state, __('Back'), 'menu')],
        ]);
    }

    private function moreGamesMenu(RegistrationState $state): array
    {
        return $this->message(__('Choose a game'), [
            [$this->button($state, __('This or That'), 'game_select_this_or_that'), $this->button($state, __('Two Truths and a Lie'), 'game_select_two_truths_one_lie')],
            [$this->button($state, __('Guess Interest'), 'game_select_guess_interest'), $this->button($state, __('Guess Number'), 'game_select_guess_number')],
            [$this->button($state, __('Daily Challenge'), 'game_daily')],
            [$this->button($state, __('Back'), 'games')],
        ]);
    }

    private function gameStatsMenu(RegistrationState $state): array
    {
        return $this->message(__('Games · Stats'), [
            [$this->button($state, __('Leaderboard'), 'game_leaderboard')],
            [$this->button($state, __('My Stats'), 'game_stats'), $this->button($state, __('My Badges'), 'game_badges')],
            [$this->button($state, __('Back'), 'games')],
        ]);
    }

    private function gameOpponentModeMenu(RegistrationState $state, GameType $type): array
    {
        return $this->message(__('🎯 حریف رو چطور انتخاب می‌کنی؟'), [
            [$this->button($state, __('🎲 حریف تصادفی'), 'game_opponent_mode_random_'.$type->value)],
            [$this->button($state, __('👥 بازی با مخاطبین'), 'game_opponent_mode_contacts_'.$type->value)],
            [$this->button($state, __('بازگشت'), 'games')],
        ]);
    }

    private function randomGameQueue(User $user, RegistrationState $state, GameType $type, string $desiredGender): array
    {
        $result = DB::transaction(function () use ($user, $type, $desiredGender) {
            $now = now();
            $expires = $now->copy()->addSeconds((int) config('discovery.matchmaking_timeout_seconds', 120));
            $profile = $user->profile;
            if ($user->status !== UserStatus::Active || ! $profile || $profile->status !== ProfileStatus::Active) {
                throw new \DomainException('پروفایلت برای بازی آماده نیست.');
            }

            $candidateRows = DB::table('game_matchmaking_queue')
                ->where('status', 'waiting')
                ->where('game_type', $type->value)
                ->where('expires_at', '>', $now)
                ->where('user_id', '<>', $user->id)
                ->orderBy('started_at')
                ->lockForUpdate()
                ->get();

            foreach ($candidateRows as $candidateRow) {
                $opponent = User::with('profile')->find($candidateRow->user_id);
                if (! $opponent || $opponent->status !== UserStatus::Active || ! $opponent->profile || $opponent->profile->status !== ProfileStatus::Active) {
                    DB::table('game_matchmaking_queue')->where('id', $candidateRow->id)->where('status', 'waiting')->update(['status' => 'expired', 'updated_at' => $now]);

                    continue;
                }
                $userGender = $profile->gender?->value;
                $opponentGender = $opponent->profile->gender?->value;
                if (($candidateRow->desired_gender && $candidateRow->desired_gender !== $userGender) || ($opponentGender && $opponentGender !== $desiredGender)) {
                    continue;
                }
                if (! app(BlockService::class)->isBlocked($user, $opponent)) {
                    $busy = GameSession::whereIn('status', [GameStatus::Waiting, GameStatus::Accepted, GameStatus::Active])
                        ->whereHas('participants', fn ($query) => $query->whereKey($user->id))
                        ->orWhere(fn ($query) => $query->whereIn('status', [GameStatus::Waiting, GameStatus::Accepted, GameStatus::Active])->whereHas('participants', fn ($q) => $q->whereKey($opponent->id)))
                        ->exists();
                    if (! $busy) {
                        $session = GameSession::create([
                            'game_type' => $type,
                            'origin' => 'random_matchmaking',
                            'status' => GameStatus::Active,
                            'created_by' => $user->id,
                            'expires_at' => $expires,
                            'state' => ['started_at' => $now->toIso8601String()],
                        ]);
                        $session->participants()->attach([$user->id, $opponent->id]);
                        DB::table('game_matchmaking_queue')->where('id', $candidateRow->id)->where('status', 'waiting')->update([
                            'status' => 'matched',
                            'matched_at' => $now,
                            'matched_session_id' => $session->id,
                            'updated_at' => $now,
                        ]);
                        DB::table('game_matchmaking_queue')->updateOrInsert(
                            ['user_id' => $user->id, 'status' => 'matched'],
                            ['game_type' => $type->value, 'desired_gender' => $desiredGender, 'started_at' => $now, 'expires_at' => $expires, 'matched_at' => $now, 'matched_session_id' => $session->id, 'created_at' => $now, 'updated_at' => $now]
                        );
                        $this->games->ensureRound($session);
                        app(SocialNotificationService::class)->queue($opponent, 'game_matched', $session->id, __('🎉 حریف پیدا شد!')."\n".__('🎮 بازی شروع شد.'), 'game_matched:'.$session->id.':'.$opponent->id, [[['text' => __('🎮 بازی'), 'callback_data' => 'd:0:game_open_'.$session->id]]]);

                        return ['matched' => true];
                    }
                }
            }

            DB::table('game_matchmaking_queue')->where('user_id', $user->id)->where('status', 'waiting')->update(['status' => 'cancelled', 'updated_at' => $now]);
            DB::table('game_matchmaking_queue')->insert([
                'user_id' => $user->id,
                'game_type' => $type->value,
                'desired_gender' => $desiredGender,
                'status' => 'waiting',
                'started_at' => $now,
                'expires_at' => $expires,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['matched' => false];
        });

        if ($result['matched']) {
            return $this->message(__('🎉 حریف پیدا شد!')."\n".__('🎮 بازی شروع شد.'));
        }

        return $this->message(__('🔎 دارم یه حریف برات پیدا می‌کنم...')."\n".__('حداکثر زمان انتظار: :minutes دقیقه', ['minutes' => Presentation::persianDigits('۲')]), [
            [$this->button($state, __('لغو جستجو'), 'game_match_cancel')],
        ]);
    }

    private function gameTypeMenu(RegistrationState $state, string $mode): array
    {
        $rows = [];
        foreach ([GameType::RockPaperScissors, GameType::TruthOrDare] as $type) {
            $rows[] = [$this->button($state, Presentation::label($type->value), 'game_type_'.$mode.'_'.$type->value)];
        }
        $rows[] = [$this->button($state, __('Back'), 'games')];

        return $this->message(__('🎮 یک بازی انتخاب کن:'), $rows);
    }

    private function twoTruthsScreen(User $user, RegistrationState $state, GameSession $session): array
    {
        $view = $this->twoTruths->view($user, $session);
        if ($view['phase'] === 'completed') {
            return app(GameHubInteraction::class)->result($user, $session);
        }
        if ($view['phase'] === 'waiting') {
            return $this->message(__('Waiting for the other player.'), [[$this->button($state, __('Refresh game'), 'game_tt_open_'.$session->id)]]);
        }
        $statements = $view['statements'];
        if ($view['phase'] === 'statements' && count($statements) < 3) {
            return $this->message(__('Enter statement ').(count($statements) + 1).__(' of 3 (one per message).'));
        }
        $text = $view['phase'] === 'statements' ? __('Privately select exactly one lie:') : __('Which statement is the lie?');
        $rows = [];
        foreach ($statements as $index => $statement) {
            $text .= "\n".($index + 1).'. '.$statement;
            $rows[] = [$this->button($state, (string) ($index + 1), 'game_tt_'.($view['phase'] === 'statements' ? 'lie' : 'guess').'_'.$session->id.'_'.$view['round'].'_'.$index)];
        }

        return $this->message($text, $rows);
    }

    private function guessInterestScreen(User $user, RegistrationState $state, GameSession $session): array
    {
        $view = $this->guessInterest->view($user, $session);
        if ($view['phase'] === 'completed') {
            return app(GameHubInteraction::class)->result($user, $session);
        }
        InteractionState::updateOrCreate(['user_id' => $user->id], ['mode' => 'game_guess_interest', 'game_context' => ['session_id' => $session->id, 'round' => $view['round']]]);
        if (! $view['your_turn']) {
            return $this->message(__('Waiting for the other player.'));
        }
        $rows = [];
        foreach ($view['options'] as $i => $option) {
            $rows[] = [$this->button($state, Presentation::label($option), 'game_gi_answer_'.$session->id.'_'.$view['round'].'_'.$i)];
        }

        return $this->message(__('Which interest belongs to your opponent?'), $rows);
    }

    private function guessNumberScreen(User $user, RegistrationState $state, GameSession $session): array
    {
        $view = $this->guessNumber->view($user, $session);
        if ($view['phase'] === 'completed') {
            return app(GameHubInteraction::class)->result($user, $session);
        }
        InteractionState::updateOrCreate(['user_id' => $user->id], ['mode' => 'game_guess_number', 'game_context' => ['session_id' => $session->id, 'turn' => $view['turn']]]);

        return $this->message($view['your_turn'] ? __('Your turn. Enter a whole number from :v1 to :v2.', ['v1' => $view['min'], 'v2' => $view['max']]) : __('Waiting for the other player.'));
    }

    private function gameOpponents(User $user, RegistrationState $state, string $mode, GameType $type, int $page): array
    {
        $query = User::where('id', '!=', $user->id)->where('status', 'active')->whereHas('profile', fn ($q) => $q->where('status', 'active'))
            ->whereNotIn('id', DB::table('user_blocks')->where('blocker_user_id', $user->id)->select('blocked_user_id'))
            ->whereNotIn('id', DB::table('user_blocks')->where('blocked_user_id', $user->id)->select('blocker_user_id'));
        if ($mode === 'contacts') {
            $query->whereIn('id', DB::table('user_contacts')->where('user_id', $user->id)->select('contact_user_id'));
        }
        $players = $query->with('profile')->orderBy('id')->paginate(5, ['*'], 'page', max(1, min(100000, $page)));
        $rows = [];
        foreach ($players as $player) {
            $name = $player->profile->display_name;
            if ($this->guard->blocked($name) || preg_match('/[0-9]{6,}/', $name) || ($player->telegram_username && stripos($name, $player->telegram_username) !== false)) {
                $name = __('Player');
            }
            if ($mode === 'contacts') {
                $age = $player->profile->age ? Presentation::persianDigits((string) $player->profile->age) : '—';
                $city = $player->profile->city?->name ?? '—';
                $name = '👤 '.$name.' | '.$age.' | '.$city.' | '.MitoId::display($player->public_mito_id);
            }
            $rows[] = [$this->button($state, $name, 'profile_'.$player->id.'_game_pick_'.$mode.'_'.$type->value.'_'.$players->currentPage())];
        }
        $nav = [];
        if ($players->currentPage() > 1) {
            $nav[] = $this->button($state, __('Previous'), 'game_pick_'.$mode.'_'.$type->value.'_'.($players->currentPage() - 1));
        }
        if ($players->hasMorePages()) {
            $nav[] = $this->button($state, __('Next'), 'game_pick_'.$mode.'_'.$type->value.'_'.($players->currentPage() + 1));
        }
        if ($nav) {
            $rows[] = $nav;
        }
        $rows[] = [$this->button($state, __('Back'), 'games')];

        return $this->message($players->isEmpty() ? __('No eligible opponent is available.') : __('Choose your opponent:'), $rows);
    }

    private function openGame(User $user, RegistrationState $state, GameSession $session): array
    {
        if ($session->status === GameStatus::Completed) {
            return app(GameHubInteraction::class)->result($user, $session);
        }
        $this->games->assertPlayable($user, $session);

        return match ($session->game_type) {
            GameType::TwoTruthsOneLie => $this->twoTruthsScreen($user, $state, $session),
            GameType::GuessInterest => $this->guessInterestScreen($user, $state, $session),
            GameType::GuessNumber => $this->guessNumberScreen($user, $state, $session),
            GameType::SpeedQuiz => $this->speedQuizScreen($state, $session),
            GameType::ThisOrThat => $this->thisOrThatScreen($state, $session),
            GameType::RockPaperScissors => $this->rpsScreen($user, $state, $session),
            GameType::TruthOrDare => $this->truthOrDareScreen($state, $session),
        };
    }

    private function truthOrDareScreen(RegistrationState $state, GameSession $session): array
    {
        return $this->message(__('Choose truth or dare:'), [
            [$this->button($state, __('Dare'), 'game_tod_dare_'.$session->id), $this->button($state, __('Truth'), 'game_tod_truth_'.$session->id)],
            [$this->button($state, __('Back'), 'games')],
        ]);
    }

    private function truthOrDarePrompt(RegistrationState $state, GameSession $session, string $choice): array
    {
        $truths = [__('Truth prompt embarrassment'), __('Truth prompt lie'), __('Truth prompt attraction')];
        $dares = [__('Dare prompt funny voice'), __('Dare prompt emojis'), __('Dare prompt compliment')];
        $prompt = collect($choice === 'truth' ? $truths : $dares)->random();

        return $this->message($prompt, [
            [$this->button($state, __('Dare'), 'game_tod_dare_'.$session->id), $this->button($state, __('Truth'), 'game_tod_truth_'.$session->id)],
            [$this->button($state, __('Back'), 'games')],
        ]);
    }

    private function rpsScreen(User $user, RegistrationState $state, GameSession $session): array
    {
        $round = DB::table('game_rounds')->where('game_session_id', $session->id)->where('round_number', $session->current_round)->value('id');
        if (DB::table('game_answers')->where('game_round_id', $round)->where('user_id', $user->id)->exists()) {
            return $this->message(__('Move recorded privately. Waiting for your opponent.'), [[$this->button($state, __('Refresh game'), 'game_open_'.$session->id)]]);
        }
        $rows = [];
        foreach (['rock' => __('✊ Rock'), 'paper' => __('✋ Paper'), 'scissors' => __('✌ Scissors')] as $move => $label) {
            $rows[] = [$this->button($state, $label, 'game_rps_answer_'.$session->id.'_'.$session->current_round.'_'.$move)];
        }
        $rows[] = [$this->button($state, __('Back'), 'games')];

        return $this->message(__('Rock Paper Scissors — first to two round wins. Choose your move:'), $rows);
    }

    private function speedQuizScreen(RegistrationState $state, GameSession $session): array
    {
        $question = $this->speedQuiz->question($session);
        $rows = [];
        foreach ($question['options'] as $index => $option) {
            $rows[] = [$this->button($state, Presentation::label($option), 'game_speed_answer_'.$session->id.'_'.$question['round'].'_'.$index)];
        }

        return $this->message(__('Speed Quiz\\nQuestion ').$question['round'].__(' of ').$question['total']."\n\n".Presentation::label($question['question']), $rows);
    }

    private function thisOrThatScreen(RegistrationState $state, GameSession $session): array
    {
        $question = $this->thisOrThat->question($session);
        $rows = [];
        foreach ($question['options'] as $index => $option) {
            $rows[] = [$this->button($state, Presentation::label($option), 'game_this_answer_'.$session->id.'_'.$question['round'].'_'.$index)];
        }

        return $this->message(__('This or That\\nQuestion ').$question['round'].__(' of ').$question['total']."\n\n".Presentation::label($question['question']), $rows);
    }

    private function searchMenu(RegistrationState $state): array
    {
        $text = __('Who would you like to see?');

        return [['method' => 'sendMessage', 'parameters' => [
            'text' => $text,
            'reply_markup' => Keyboard::reply(Keyboard::searchReply(), true),
        ]]];
    }

    private function genderMenu(RegistrationState $state, string $mode): array
    {
        return $this->message(__('Who are you looking for?'), [[$this->button($state, __('Male'), $mode.'_male'), $this->button($state, __('Female'), $mode.'_female')], [$this->button($state, __('Back'), 'back')]]);
    }

    private function interestMenu(RegistrationState $state, int $interestId): array
    {
        if (! Interest::whereKey($interestId)->exists()) {
            return $this->message(__('That interest is no longer available.'));
        }

        return $this->message(__('Choose a gender for this interest.'), [[$this->button($state, __('Male'), "interest_{$interestId}_male"), $this->button($state, __('Female'), "interest_{$interestId}_female")], [$this->button($state, __('Back'), 'search')]]);
    }

    private function interestChoices(RegistrationState $state): array
    {
        $buttons = [];
        foreach (Interest::orderBy('name')->get() as $interest) {
            $buttons[] = [$this->button($state, Presentation::label($interest->name), 'interest_'.$interest->id)];
        }
        $buttons[] = [$this->button($state, __('Back'), 'search')];

        return $this->message(__('Choose a normalized interest.'), $buttons);
    }

    private function distanceMenu(RegistrationState $state, string $mode, string $gender): array
    {
        return $this->message(__('Choose a distance range.'), [
            [$this->button($state, __('0-5 km'), "distance_05_{$gender}"), $this->button($state, __('5-10 km'), "distance_510_{$gender}")],
            [$this->button($state, __('10-15 km'), "distance_1015_{$gender}"), $this->button($state, __('15-20 km'), "distance_1520_{$gender}")],
            [$this->button($state, __('0-20 km'), "distance_020_{$gender}"), $this->button($state, __('Back'), $mode === 'nearby' ? 'menu' : 'search')],
        ]);
    }

    private function distance(User $user, RegistrationState $state, string $gender, string $range, int $page): array
    {
        $rangeEnum = DistanceRange::tryFrom($range);
        $genderEnum = Gender::tryFrom($gender);
        if (! $rangeEnum || ! $genderEnum) {
            return $this->message(__('That distance option is invalid.'));
        }

        return $this->cards($state, $this->discovery->distance($user, $genderEnum, $rangeEnum, $page), DiscoveryType::Distance, $gender, $range);
    }

    private function anonymous(User $user, RegistrationState $state, string $gender): array
    {
        $genderEnum = Gender::tryFrom($gender);
        if (! $genderEnum) {
            return $this->message(__('That gender option is invalid.'));
        }
        $previous = DB::table('matchmaking_searches')->where('user_id', $user->id)->first();
        $generation = ($previous->generation ?? 0) + 1;
        $now = now();
        DB::table('matchmaking_searches')->updateOrInsert(['user_id' => $user->id], [
            'gender' => $genderEnum->value,
            'status' => 'waiting',
            'generation' => $generation,
            'matched_user_id' => null,
            'started_at' => $now,
            'expires_at' => $now->copy()->addSeconds(max(10, (int) config('discovery.matchmaking_timeout_seconds', 120))),
            'created_at' => $previous->created_at ?? $now,
            'updated_at' => $now,
        ]);
        MatchmakingPoll::dispatch($user->id, $generation)->onConnection('database')->onQueue('telegram')->delay($now->copy()->addSeconds(3));

        return $this->message(__('Looking for someone for you... Up to two minutes.'), [
            [$this->button($state, __('Cancel search'), 'match_cancel')],
        ]);
    }

    private function list(User $user, RegistrationState $state, DiscoveryType $type, string $gender, ?int $interestId, int $page): array
    {
        $genderEnum = Gender::tryFrom($gender);
        if (! $genderEnum) {
            return $this->message(__('That gender option is invalid.'));
        }
        $results = match ($type) {
            DiscoveryType::City => $this->discovery->city($user, $genderEnum, $page), DiscoveryType::Age => $this->discovery->age($user, $genderEnum, $page), DiscoveryType::NewUsers => $this->discovery->newUsers($user, $genderEnum, $page), DiscoveryType::Interest => $this->discovery->interest($user, $interestId ?? 0, $genderEnum, $page), default => $this->discovery->city($user, $genderEnum, $page)
        };

        return $this->cards($state, $results, $type, $gender, $interestId === null ? null : (string) $interestId);
    }

    private function cards(RegistrationState $state, LengthAwarePaginator $results, DiscoveryType $type, string $gender, ?string $range = null): array
    {
        $context = $type === DiscoveryType::Interest ? ($range ?? '0') : ($type === DiscoveryType::Distance ? ($range ?? '020') : null);
        $pagePrefix = $context === null ? 'page_'.$type->value.'_'.$gender.'_' : 'page_'.$type->value.'_'.$context.'_'.$gender.'_';
        $rows = [];
        $text = __('Results:');
        foreach ($results as $profile) {
            $public = PublicProfile::fromProfile($profile, isset($profile->distance_km) ? (float) $profile->distance_km : null);
            $mitoId = MitoId::display($profile->user->public_mito_id);
            $city = Presentation::label($public->city);
            $badges = trim(($public->isGold ? '⭐ طلایی ' : '').$public->activity);
            $text .= "\n\n👤 {$public->name}، {$public->age} ساله، {$city}، {$mitoId}";
            if ($badges !== '') {
                $text .= "\n{$badges}";
            }
            if ($public->distanceKm !== null) {
                $text .= ' — '.__(':distance کیلومتر', ['distance' => round($public->distanceKm, 1)]);
            }
            $label = "👤 {$public->name} | {$public->age} | {$city} | {$mitoId}";
            $rows[] = [$this->button($state, $label, 'profile_'.$profile->user_id.'_'.$pagePrefix.$results->currentPage())];
        }
        $rows = array_merge($rows, Keyboard::pagination('d', $state->revision + 1, $results->currentPage(), $results->hasMorePages(), $pagePrefix, 1));
        $rows[] = [$this->button($state, __('Back'), 'search')];

        return $this->message($results->isEmpty() ? __('Nobody matched this search yet.') : $text, $rows);
    }

    private function eventsMenu(User $user, RegistrationState $state): array
    {
        $events = $this->events->listing($user, 'new');
        $rows = [[$this->button($state, __('Nearby Events'), 'event_nearby'), $this->button($state, __('Today'), 'event_today')], [$this->button($state, __('This Week'), 'event_week'), $this->button($state, __('Newest'), 'event_new')], [$this->button($state, __('By Category'), 'event_categories')], [$this->button($state, __('My Events'), 'event_mine'), $this->button($state, __('Joined Events'), 'event_joined')], [$this->button($state, __('Create Event'), 'event_create')]];
        foreach ($events as $event) {
            $rows[] = [$this->button($state, $event->title, 'event_view_'.$event->id)];
        }
        $rows[] = [$this->button($state, __('Back'), 'menu')];

        return $this->message(__('Events'), $rows);
    }

    private function eventCards(RegistrationState $state, LengthAwarePaginator $events, string $filter): array
    {
        $rows = [];
        foreach ($events as $event) {
            $rows[] = [$this->button($state, $event->title, 'event_view_'.$event->id)];
        }
        if ($events->isEmpty()) {
            $rows[] = [$this->button($state, __('Back'), 'events')];
        }
        $nav = [];
        if ($events->currentPage() > 1) {
            $nav[] = $this->button($state, __('Previous'), 'event_page_'.$filter.'_'.($events->currentPage() - 1));
        }
        if ($events->hasMorePages()) {
            $nav[] = $this->button($state, __('Next'), 'event_page_'.$filter.'_'.($events->currentPage() + 1));
        }
        if ($nav) {
            $rows[] = $nav;
        }
        $rows[] = [$this->button($state, __('Back'), 'events')];

        return $this->message(__('Events'), $rows);
    }

    private function eventWizardText(User $user, RegistrationState $state, InteractionState $interaction, string $text): array
    {
        $context = $interaction->event_context ?? ['step' => 'title'];
        if ($context['step'] === 'title') {
            if (mb_strlen($text) < 3 || mb_strlen($text) > 120) {
                return $this->message(__('Title must be between 3 and 120 characters.'));
            }
            $context['title'] = $text;
            $context['step'] = 'category';
            $interaction->update(['event_context' => $context]);

            return $this->message(__('Event step 2 of 8')."\n".__('Choose a category.'), array_merge(array_map(fn ($c) => [$this->button($state, Presentation::label($c->name), 'event_wizard_category_'.$c->id)], EventCategory::orderBy('name')->get()->all()), Keyboard::navigation('d', $state->revision + 1, 'event_step_back', 'event_cancel_wizard')));
        }
        if ($context['step'] === 'description') {
            $context['description'] = $text === '/skip' ? null : $text;
            $context['step'] = 'day';
            $interaction->update(['event_context' => $context]);

            return $this->eventDate($state);
        }
        if ($context['step'] === 'time_custom') {
            return $this->selectEventTime($state, $interaction, Presentation::asciiDigits($text));
        }
        if ($context['step'] === 'capacity_custom') {
            $number = Presentation::asciiDigits($text);
            if (! ctype_digit($number) || (int) $number < 1 || (int) $number > 10000) {
                return $this->message(__('Choose a valid capacity.'), Keyboard::navigation('d', $state->revision + 1, 'event_step_back', 'event_cancel_wizard'));
            }
            $context['capacity'] = (int) $number;
            $context['step'] = 'location';
            $interaction->update(['event_context' => $context]);

            return $this->eventLocation($state);
        }
        // Accept old date/time input from a draft started before this UX update.
        if ($context['step'] === 'date' || $context['step'] === 'day') {
            try {
                $start = CarbonImmutable::createFromFormat('Y-m-d H:i', Presentation::asciiDigits($text), app()->getLocale() === 'fa' ? config('presentation.timezone') : config('app.timezone'));
            } catch (\Throwable $e) {
                $start = null;
            }
            if (! $start || $start->lt(now())) {
                return $this->eventDate($state);
            }
            $context['starts_at'] = $start->utc()->toDateTimeString();
            $context['step'] = 'capacity';
            $interaction->update(['event_context' => $context]);

            return $this->eventCapacity($state);
        }
        if ($context['step'] === 'capacity') {
            $text = Presentation::asciiDigits($text);
            if (! ctype_digit($text) || (int) $text < 0) {
                return $this->message(__('Capacity must be a positive number, or 0 for unlimited.'));
            }
            $context['capacity'] = (int) $text ?: null;
            $context['step'] = 'location';
            $interaction->update(['event_context' => $context]);

            return $this->eventLocation($state);
        }

        return $this->message(__('Use the buttons to continue.'));
    }

    private function eventDate(RegistrationState $state, int $page = 0): array
    {
        $interaction = InteractionState::firstOrCreate(['user_id' => $state->user_id]);
        $context = $interaction->event_context ?? [];
        $context['day_page'] = $page;
        $interaction->update(['event_context' => $context]);

        return [['method' => 'sendMessage', 'parameters' => [
            'text' => __('Event step 4 of 8')."\n".__('Which day?'),
            'reply_markup' => Keyboard::reply(Keyboard::eventDaysReply($page)),
        ]]];
    }

    private function eventTime(RegistrationState $state): array
    {
        return [['method' => 'sendMessage', 'parameters' => [
            'text' => __('Event step 5 of 8')."\n".__('What time?'),
            'reply_markup' => Keyboard::reply(Keyboard::eventTimesReply()),
        ]]];
    }

    private function selectEventTime(RegistrationState $state, InteractionState $interaction, string $time): array
    {
        $context = $interaction->event_context ?? [];
        if (! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time) || ! isset($context['day'])) {
            return $this->message(__('Choose a valid time, like 19:30.'), Keyboard::navigation('d', $state->revision + 1, 'event_step_back', 'event_cancel_wizard'));
        }
        $start = CarbonImmutable::parse($context['day'].' '.$time, config('presentation.timezone'));
        if ($start->isPast()) {
            return $this->eventTime($state);
        }
        $context['starts_at'] = $start->utc()->toDateTimeString();
        $context['step'] = 'capacity';
        $interaction->update(['event_context' => $context]);

        return $this->eventCapacity($state);
    }

    private function eventCapacity(RegistrationState $state): array
    {
        return [['method' => 'sendMessage', 'parameters' => [
            'text' => __('Event step 6 of 8')."\n".__('How many people can join?'),
            'reply_markup' => Keyboard::reply(Keyboard::capacityReply()),
        ]]];
    }

    private function eventLocation(RegistrationState $state): array
    {
        return $this->message(__('Event step 7 of 8')."\n".__('Where will it happen?'), [
            [$this->button($state, __('Use My City'), 'event_location_city')],
            [$this->button($state, __('Skip Precise Location'), 'event_location_skip')],
            [$this->button($state, __('Back'), 'event_step_back'), $this->button($state, __('Cancel'), 'event_cancel_wizard')],
        ]);
    }

    private function eventBack(RegistrationState $state, InteractionState $interaction): array
    {
        $context = $interaction->event_context ?? [];
        $previous = [
            'category' => 'title', 'description' => 'category', 'day' => 'description',
            'time' => 'day', 'time_custom' => 'day', 'capacity' => 'time',
            'capacity_custom' => 'time', 'location' => 'capacity', 'confirm' => 'location',
        ][$context['step'] ?? 'title'] ?? 'title';
        $context['step'] = $previous;
        $interaction->update(['event_context' => $context]);

        return match ($previous) {
            'category' => $this->message(__('Event step 2 of 8')."\n".__('Choose a category.'), array_merge(array_map(fn ($c) => [$this->button($state, Presentation::label($c->name), 'event_wizard_category_'.$c->id)], EventCategory::orderBy('name')->get()->all()), Keyboard::navigation('d', $state->revision + 1, 'event_step_back', 'event_cancel_wizard'))),
            'description' => $this->message(__('Event step 3 of 8')."\n".__('Tell us a little about it. Send /skip to leave this blank.'), Keyboard::navigation('d', $state->revision + 1, 'event_step_back', 'event_cancel_wizard')),
            'day' => $this->eventDate($state),
            'time' => $this->eventTime($state),
            'capacity' => $this->eventCapacity($state),
            'location' => $this->eventLocation($state),
            default => $this->message(__('Event step 1 of 8')."\n".__('What should we call your event?'), Keyboard::navigation('d', $state->revision + 1, 'events', 'event_cancel_wizard')),
        };
    }

    private function eventDateLabel(string $value): string
    {
        return CarbonImmutable::parse($value)->setTimezone(config('presentation.timezone'))->translatedFormat('l j F، H:i');
    }

    private function publishEvent(User $user, RegistrationState $state, InteractionState $interaction): array
    {
        $data = $interaction->event_context ?? [];
        $data['city_id'] ??= $user->profile?->city_id;
        $event = $this->events->create($user, $data);
        $interaction->update(['mode' => 'menu', 'event_context' => null]);

        return $this->message(__('Event published: ').$event->title, [[$this->button($state, __('Back to Events'), 'events')]]);
    }

    private function eventDetail(User $user, RegistrationState $state, Event $event): array
    {
        $count = $event->participants()->wherePivot('status', 'joined')->count();
        $capacity = $event->capacity === null ? __('unlimited') : $event->capacity;
        $buttons = [];
        if ($event->creator_user_id === $user->id) {
            $buttons[] = [$this->button($state, __('Participants'), 'event_participants_'.$event->id), $this->button($state, __('Cancel Event'), 'event_cancel_'.$event->id)];
        } else {
            $buttons[] = [$this->button($state, __('Join Event'), 'event_join_'.$event->id), $this->button($state, __('Leave Event'), 'event_leave_'.$event->id)];
        }
        $buttons[] = [$this->button($state, __('Participants'), 'event_participants_'.$event->id), $this->button($state, __('Report'), 'event_report_'.$event->id)];
        $buttons[] = [$this->button($state, __('Back'), 'events')];

        return $this->message($event->title."\n".Presentation::label($event->category?->name ?? '')."\n".Presentation::date($event->starts_at).__("\nParticipants: :v1/:v2", ['v1' => $count, 'v2' => $capacity]), $buttons);
    }

    private function myProfile(User $user, RegistrationState $state, InteractionState $interaction): array
    {
        $profile = $user->profile?->load(['city', 'interests', 'user']);
        if (! $profile) {
            return $this->message(__('Your profile is unavailable.'));
        }
        $interaction->update(['mode' => 'menu', 'direct_context' => null]);

        return $this->profileMessages(PublicProfile::fromProfile($profile), [
            [$this->button($state, __('Edit name'), 'profile_edit_name'), $this->button($state, __('Edit age'), 'profile_edit_age')],
            [$this->button($state, __('Edit city'), 'profile_edit_city'), $this->button($state, __('Edit interests'), 'profile_edit_interests')],
            [$this->button($state, __('Change photo'), 'profile_edit_photo'), $this->button($state, __('Change voice'), 'profile_edit_voice')],
            [$this->button($state, __('Profile preview'), 'profile'), $this->button($state, __('Home'), 'menu')],
        ]);
    }

    private function startProfileEdit(User $user, RegistrationState $state, InteractionState $interaction, string $mode): array
    {
        $interaction->update(['mode' => $mode, 'direct_context' => in_array($mode, ['profile_edit_age', 'profile_edit_city'], true) ? ['page' => 0] : []]);

        return match ($mode) {
            'profile_edit_name' => $this->replyMessage(__('Write your new name.'), [[__('Cancel')]]),
            'profile_edit_age' => $this->replyMessage(__('How old are you?'), Keyboard::ageReply(0)),
            'profile_edit_city' => $this->replyMessage(__('Where do you live? Choose a province.'), Keyboard::provinceReply(0)),
            'profile_edit_photo' => $this->replyMessage(__('Send your new profile photo.'), [[__('Delete current photo')], [__('Cancel')]]),
            'profile_edit_voice' => $this->replyMessage(__('Send a new voice introduction.'), [[__('Delete current voice')], [__('Cancel')]]),
        };
    }

    private function profileEditInput(User $user, RegistrationState $state, InteractionState $interaction, IncomingUpdate $update): array
    {
        $profile = $user->profile()->with(['city', 'interests', 'user'])->firstOrFail();
        $input = $update->input(null);
        $text = trim($input->text ?? '');
        if ($text === __('Cancel') || $text === __('Back')) {
            return $this->myProfile($user, $state, $interaction);
        }
        if ($interaction->mode === 'profile_edit_name') {
            if (Validator::make(['name' => $text], ['name' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\p{L}\p{M}][\p{L}\p{M} \x{200C}\x{200D}\x{0027}-]*$/u']])->fails()) {
                return $this->replyMessage(__('Write a name between 2 and 50 letters.'), [[__('Cancel')]]);
            }
            $profile->update(['display_name' => $text]);
            $interaction->update(['mode' => 'menu', 'direct_context' => null]);

            return array_merge($this->message(__('Profile name changed.')), $this->myProfile($user, $state, $interaction));
        }
        if ($interaction->mode === 'profile_edit_age') {
            $context = $interaction->direct_context ?? ['page' => 0];
            if (in_array($text, [__('Previous'), __('Next')], true)) {
                $page = max(0, min(3, (int) ($context['page'] ?? 0) + ($text === __('Next') ? 1 : -1)));
                $interaction->update(['direct_context' => ['page' => $page]]);

                return $this->replyMessage(__('How old are you?'), Keyboard::ageReply($page));
            }
            $age = (int) Presentation::asciiDigits($text);
            if ($age < 18 || $age > 80) {
                return $this->replyMessage(__('Choose an age from 18 to 80.'), Keyboard::ageReply((int) ($context['page'] ?? 0)));
            }
            $profile->update(['birth_date' => CarbonImmutable::today()->subYearsNoOverflow($age)]);
            $interaction->update(['mode' => 'menu', 'direct_context' => null]);

            return array_merge($this->message(__('Your age changed to :age.', ['age' => $age])), $this->myProfile($user, $state, $interaction));
        }
        if ($interaction->mode === 'profile_edit_city') {
            return $this->profileCityInput($user, $state, $interaction, $profile, $text);
        }
        if ($interaction->mode === 'profile_edit_photo') {
            if ($text === __('Delete current photo')) {
                $profile->update(['photo_file_id' => null]);
            } elseif ($input->photo) {
                $profile->update(['photo_file_id' => $input->photo]);
            } else {
                return $this->replyMessage(__('Send a photo or choose Cancel.'), [[__('Delete current photo')], [__('Cancel')]]);
            }
            $interaction->update(['mode' => 'menu', 'direct_context' => null]);

            return array_merge($this->message(__('Profile photo changed.')), $this->myProfile($user, $state, $interaction));
        }
        if ($interaction->mode === 'profile_edit_voice') {
            if ($text === __('Delete current voice')) {
                $profile->update(['voice_file_id' => null, 'voice_duration' => null]);
            } elseif ($input->voice && $input->voiceDuration !== null && $input->voiceDuration <= 120) {
                $profile->update(['voice_file_id' => $input->voice, 'voice_duration' => $input->voiceDuration]);
            } else {
                return $this->replyMessage(__('Send a voice introduction up to 120 seconds, or choose Cancel.'), [[__('Delete current voice')], [__('Cancel')]]);
            }
            $interaction->update(['mode' => 'menu', 'direct_context' => null]);

            return array_merge($this->message(__('Voice introduction changed.')), $this->myProfile($user, $state, $interaction));
        }

        return $this->myProfile($user, $state, $interaction);
    }

    private function profileCityInput(User $user, RegistrationState $state, InteractionState $interaction, Profile $profile, string $text): array
    {
        $context = $interaction->direct_context ?? ['page' => 0];
        $page = (int) ($context['page'] ?? 0);
        if (in_array($text, [__('Previous'), __('Next')], true)) {
            $page = max(0, $page + ($text === __('Next') ? 1 : -1));
            $context['page'] = $page;
            $interaction->update(['direct_context' => $context]);

            return $this->replyMessage(isset($context['province_id']) ? __('Choose your city.') : __('Choose your province.'), isset($context['province_id']) ? Keyboard::cityReply((int) $context['province_id'], $page) : Keyboard::provinceReply($page));
        }
        if ($text === __('Provinces')) {
            $interaction->update(['direct_context' => ['page' => 0]]);

            return $this->replyMessage(__('Choose your province.'), Keyboard::provinceReply(0));
        }
        if (! isset($context['province_id'])) {
            foreach (array_slice(Keyboard::provinces($page), 0, 12) as $province) {
                if ($text === Presentation::label($province->name)) {
                    $interaction->update(['direct_context' => ['province_id' => $province->id, 'page' => 0]]);

                    return $this->replyMessage(__('Choose your city.'), Keyboard::cityReply($province->id, 0));
                }
            }

            return $this->replyMessage(__('Choose a province using the buttons.'), Keyboard::provinceReply($page));
        }
        foreach (array_slice(Keyboard::cities((int) $context['province_id'], $page), 0, 12) as $city) {
            if ($text === Presentation::label($city->name)) {
                $profile->update(['city_id' => $city->id]);
                $interaction->update(['mode' => 'menu', 'direct_context' => null]);

                return array_merge($this->message(__('Profile city changed.')), $this->myProfile($user, $state, $interaction));
            }
        }

        return $this->replyMessage(__('Choose a city using the buttons.'), Keyboard::cityReply((int) $context['province_id'], $page));
    }

    private function profileInterestEditor(User $user, RegistrationState $state): array
    {
        $selected = $user->profile->interests()->pluck('interests.id')->all();
        $rows = [];
        foreach (Interest::orderBy('name')->get() as $interest) {
            $rows[] = [$this->button($state, (in_array($interest->id, $selected, true) ? ' ' : '').Presentation::label($interest->name), 'profile_interest_'.$interest->id)];
        }
        $rows[] = [$this->button($state, __('Save interests'), 'profile_interests_save')];
        $rows[] = [$this->button($state, __('Cancel'), 'profile')];

        return $this->message(__('Choose your interests.'), $rows);
    }

    private function replyMessage(string $text, array $rows): array
    {
        return [['method' => 'sendMessage', 'parameters' => ['text' => $text, 'reply_markup' => Keyboard::reply($rows, true)]]];
    }

    public function lookupProfile(User $viewer, string $mitoId, RegistrationState $state): array
    {
        $target = User::findByMitoId($mitoId);
        $profile = $target?->profile()->with(['city', 'interests', 'user'])->where('status', 'active')->first();
        if (! $profile) {
            $interaction = InteractionState::firstOrCreate(['user_id' => $viewer->id]);
            $active = $this->conversations->activeFor($viewer);
            $back = $active ? 'open_'.$active->id : 'back';

            return $this->message(__('No user was found with this Mito ID.'), [
                [Keyboard::button($active ? 's' : 'd', $state->revision + 1, __('Back'), $back)],
            ]);
        }

        return $this->card($viewer, $state, new DiscoveryResult($profile), 'menu');
    }

    private function card(User $viewer, RegistrationState $state, DiscoveryResult $result, string $back = 'search'): array
    {
        $public = $result->publicProfile();
        $active = $this->conversations->activeFor($viewer);
        $partnerId = $active ? ($active->user_low_id === $viewer->id ? $active->user_high_id : $active->user_low_id) : null;
        $rows = Keyboard::profileActions($state->revision + 1, $result->profile->user_id, $back, $active?->id, $partnerId === $result->profile->user_id);

        return $this->profileMessages($public, $rows);
    }

    public function chatPartnerProfile(User $partner, int $conversationId, int $revision): array
    {
        $profile = $partner->profile()->with(['city', 'interests', 'user'])->where('status', 'active')->firstOrFail();

        return $this->profileMessages(
            PublicProfile::fromProfile($profile),
            [[Keyboard::button('s', $revision, __('Return to chat'), 'open_'.$conversationId)]],
        );
    }

    public function requestProfile(User $requester, int $requestId, int $revision): array
    {
        $profile = $requester->profile()->with(['city', 'interests', 'user'])->where('status', 'active')->firstOrFail();

        return $this->profileMessages(
            PublicProfile::fromProfile($profile),
            Keyboard::chatRequestDecision($revision, $requestId),
        );
    }

    private function profileMessages(PublicProfile $public, array $rows): array
    {
        $text = $public->text();
        $parameters = ['reply_markup' => ['inline_keyboard' => $rows]];
        if ($public->photoFileId && mb_strlen($text) <= 1024) {
            $messages = [['method' => 'sendPhoto', 'parameters' => $parameters + ['photo' => $public->photoFileId, 'caption' => $text]]];
        } else {
            $messages = $public->photoFileId ? [['method' => 'sendPhoto', 'parameters' => ['photo' => $public->photoFileId]]] : [];
            $messages[] = ['method' => 'sendMessage', 'parameters' => $parameters + ['text' => $text]];
        }
        if ($public->voiceFileId) {
            $messages[] = ['method' => 'sendVoice', 'parameters' => ['voice' => $public->voiceFileId, 'caption' => __('Voice introduction')]];
        }

        return $messages;
    }

    private function toggleBulk(User $user, RegistrationState $state, InteractionState $interaction, int $id): array
    {
        if (! $this->gold->isGold($user)) {
            return $this->message(__('Gold membership is required.'));
        }
        $selected = $interaction->bulk_selection ?? [];
        if (in_array($id, $selected, true)) {
            $selected = array_values(array_diff($selected, [$id]));
        } else {
            $selected[] = $id;
        }
        if (count($selected) > $this->limits->maxRecipients()) {
            return $this->message(__('You can select up to ').$this->limits->maxRecipients().__(' recipients.'));
        }
        $interaction->update(['bulk_mode' => 'select', 'bulk_selection' => $selected]);

        return $this->message(__('Selected: ').count($selected), [[$this->button($state, __('Send Chat Requests'), 'bulk_chat'), $this->button($state, __('Send Direct Message'), 'bulk_direct')], [$this->button($state, __('Clear Selection'), 'bulk_clear'), $this->button($state, __('Cancel'), 'bulk_cancel')]]);
    }

    private function executeBulkChat(User $user, RegistrationState $state, InteractionState $interaction): array
    {
        $ids = $interaction->bulk_selection ?? [];
        $users = User::whereIn('id', $ids)->get();
        $result = $this->bulkChats->send($user, $users->all(), 'bulk-chat:'.$user->id.':'.sha1(json_encode($ids)));
        $interaction->update(['bulk_mode' => null, 'bulk_selection' => null]);

        return $this->message(__("Sent: :v1\nSkipped: :v2", ['v1' => $result['sent'], 'v2' => $result['skipped']]));
    }

    private function executeBulkDirect(User $user, RegistrationState $state, InteractionState $interaction): array
    {
        $context = $interaction->bulk_context ?? [];
        $ids = $interaction->bulk_selection ?? [];
        try {
            $result = $this->bulkDirects->send($user, User::whereIn('id', $ids)->get()->all(), (string) ($context['text'] ?? ''), (string) ($context['key'] ?? 'bulk-direct:'.$user->id));
        } catch (\Throwable $e) {
            return $this->message(Presentation::error($e->getMessage()), [[$this->button($state, __('Back'), 'bulk_cancel')]]);
        }
        $interaction->update(['bulk_mode' => null, 'bulk_selection' => null, 'bulk_context' => null]);

        return $this->message(__("Sent to :v1 users\nCoins charged: :v2", ['v1' => $result['sent'], 'v2' => $result['required']]));
    }

    private function goldScreen(User $user, RegistrationState $state): array
    {
        if (! $this->gold->isGold($user)) {
            return $this->message(__("Gold Membership\nStatus: Not Active\nGold purchase will be enabled later."), [[$this->button($state, __('Gold Benefits'), 'gold_benefits')], [$this->button($state, __('Back'), 'menu')]]);
        } $membership = $this->gold->active($user);

        return $this->message(__('Gold Active\\nExpires: ').Presentation::date($membership->ends_at), [[$this->button($state, __('Bulk Usage'), 'gold_usage'), $this->button($state, __('Gold Benefits'), 'gold_benefits')], [$this->button($state, __('Back'), 'menu')]]);
    }

    private function contactsScreen(User $user, RegistrationState $state): array
    {
        $contacts = $this->contacts->list($user)->take(20);
        $rows = [];
        foreach ($contacts as $contact) {
            $rows[] = [$this->button($state, $contact->contact->profile?->display_name ?? __('Contact'), 'profile_'.$contact->contact_user_id.'_contacts')];
        } $rows[] = [$this->button($state, __('Back'), 'menu')];

        return $this->message(__('Contacts'), $rows);
    }

    private function message(string $text, array $keyboard = []): array
    {
        $params = ['text' => $text];
        if ($keyboard) {
            $params['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        return [['method' => 'sendMessage', 'parameters' => $params]];
    }

    private function button(RegistrationState $state, string $label, string $value): array
    {
        return Keyboard::button('d', $state->revision + 1, $label, $value);
    }

    private function value(IncomingUpdate $update, RegistrationState $state): ?string
    {
        if ($update->callback() === null) {
            return match (Presentation::input(trim($update->input(null)->text ?? ''))) {
                'Search People' => 'search', 'Anonymous Search' => 'anonymous', 'Nearby' => 'nearby', default => 'menu'
            };
        }
        $parts = explode(':', $update->callback(), 3);
        if (count($parts) !== 3 || $parts[0] !== 'd' || ((int) $parts[1] !== $state->revision && ! str_starts_with($parts[2], 'game_'))) {
            return null;
        }

        return $parts[2];
    }
}
