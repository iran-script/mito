<?php

namespace App\Domain\Telegram;

use App\Domain\Profiles\City;
use App\Domain\Profiles\Interest;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\Province;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Profiles\RegistrationStep;
use App\Support\Presentation;
use Carbon\CarbonImmutable;

final class Keyboard
{
    public static function home(int $revision): array
    {
        return [
            [self::button('d', $revision, __('Find people'), 'search', 'success')],
            [self::button('d', $revision, __('Games'), 'games'), self::button('d', $revision, __('Events'), 'events')],
            [self::button('d', $revision, __('Contacts'), 'contacts'), self::button('d', $revision, __('My profile'), 'profile')],
            [self::button('s', $revision, __('Wallet'), 'wallet'), self::button('d', $revision, __('More'), 'more')],
        ];
    }

    public static function reply(array $rows, bool $persistent = false): array
    {
        return ['keyboard' => $rows, 'resize_keyboard' => true, 'is_persistent' => $persistent];
    }

    public static function chatReply(bool $protected): array
    {
        return [
            [__('View chat partner profile')],
            [$protected ? __('Disable private chat') : __('Enable private chat'), __('Direct to chat partner')],
            [__('End chat')],
        ];
    }

    public static function chatAction(string $text): ?string
    {
        return match ($text) {
            __('View chat partner profile') => 'chat_partner_profile',
            __('Enable private chat') => 'chat_protect_on',
            __('Disable private chat') => 'chat_protect_off',
            __('Direct to chat partner') => 'chat_direct',
            __('End chat') => 'close_chat',
            default => null,
        };
    }

    public static function homeReply(): array
    {
        return [
            [[
                'text' => __('Find people'),
                'style' => 'success',
            ]],
            [__('Games'), __('Events')],
            [__('Contacts'), __('My profile')],
            [__('Coins navigation'), __('More')],
        ];
    }

    public static function searchReply(): array
    {
        return [
            [__('Anonymous search')],
            [__('Same city'), __('Same age')],
            [__('New people'), __('By interest')],
            [__('Nearby'), __('Back')],
        ];
    }

    public static function ageReply(int $page): array
    {
        $page = max(0, min(3, $page));
        $first = 18 + 18 * $page;
        $rows = self::grid(array_map(strval(...), range($first, min(80, $first + 17))), 6);
        $navigation = [];
        if ($page > 0) {
            $navigation[] = __('Previous');
        }
        if ($first + 17 < 80) {
            $navigation[] = __('Next');
        }
        if ($navigation) {
            $rows[] = $navigation;
        }
        $rows[] = [__('Back')];

        return $rows;
    }

    public static function confirmReply(): array
    {
        return [[__('Confirm')], [__('Edit profile'), __('Back')]];
    }

    public static function genderReply(): array
    {
        return [[__('Man'), __('Woman')], [__('Back')]];
    }

    public static function provinces(int $page): array
    {
        return Province::orderBy('name')->offset(max(0, $page) * 12)->limit(13)->get()->all();
    }

    public static function cities(int $provinceId, int $page): array
    {
        return City::where('province_id', $provinceId)->orderBy('name')->offset(max(0, $page) * 12)->limit(13)->get()->all();
    }

    public static function interestsReply(Profile $profile): array
    {
        $selected = $profile->interests()->pluck('interests.id')->all();
        $labels = Interest::orderBy('name')->get()->map(fn ($interest) => (in_array($interest->id, $selected, true) ? 'أ¢إ“â€œ ' : '').Presentation::label($interest->name)
        )->all();
        $rows = self::grid($labels, 3);
        $rows[] = [__('Confirm interests'), __('Skip')];
        $rows[] = [__('Back')];

        return $rows;
    }

    public static function provinceReply(int $page): array
    {
        $items = self::provinces($page);
        $rows = self::grid(array_map(fn ($item) => Presentation::label($item->name), array_slice($items, 0, 12)), 3);
        $rows = array_merge($rows, self::replyPage($page, count($items) > 12));
        $rows[] = [__('Back')];

        return $rows;
    }

    public static function cityReply(int $provinceId, int $page): array
    {
        $items = self::cities($provinceId, $page);
        $rows = self::grid(array_map(fn ($item) => Presentation::label($item->name), array_slice($items, 0, 12)), 3);
        $rows = array_merge($rows, self::replyPage($page, count($items) > 12));
        $rows[] = [__('Provinces'), __('Back')];

        return $rows;
    }

    private static function replyPage(int $page, bool $more): array
    {
        $navigation = [];
        if ($page > 0) {
            $navigation[] = __('Previous');
        }
        if ($more) {
            $navigation[] = __('Next');
        }

        return $navigation ? [$navigation] : [];
    }

    public static function homeAction(string $text): ?array
    {
        $actions = [
            'Find people' => ['d', 'search'],
            'Games' => ['d', 'games'], 'Events' => ['d', 'events'],
            'Contacts' => ['d', 'contacts'], 'My profile' => ['d', 'profile'],
            'Coins navigation' => ['s', 'wallet'], 'More' => ['d', 'more'],
            'Home' => ['d', 'menu'],
        ];
        foreach ($actions as $label => $action) {
            if ($text === __($label)) {
                return $action;
            }
        }

        return null;
    }

    public static function searchAction(string $text, string $mode): ?string
    {
        if ($mode !== 'search') {
            return null;
        }
        if ($text === __('Back')) {
            return 'menu';
        }
        $filters = [
            'Anonymous search' => 'anonymous', 'Same city' => 'city',
            'Same age' => 'age', 'New people' => 'new',
            'By interest' => 'interest', 'Nearby' => 'nearby',
        ];
        foreach ($filters as $label => $action) {
            if ($text === __($label)) {
                return $action;
            }
        }

        return null;
    }

    public static function registrationChoice(string $text, RegistrationState $state): ?string
    {
        $context = $state->selection_context ?? [];
        $page = max(0, (int) ($context['page'] ?? 0));
        if ($text === __('Back')) {
            return 'back';
        }
        if ($text === __('Skip') && in_array($state->step, [RegistrationStep::Photo, RegistrationStep::Voice, RegistrationStep::Interests], true)) {
            return 'skip';
        }
        if ($state->step === RegistrationStep::Age) {
            $age = Presentation::asciiDigits($text);
            if (ctype_digit($age) && (int) $age >= 18 && (int) $age <= 80) {
                return 'age_'.$age;
            }
            if ($text === __('Previous') && $page > 0) {
                return 'age_page_'.($page - 1);
            }
            if ($text === __('Next') && $page < 3) {
                return 'age_page_'.($page + 1);
            }
        }
        if ($state->step === RegistrationStep::Gender) {
            return match ($text) {
                __('Man') => 'male', __('Woman') => 'female', default => null,
            };
        }
        if ($state->step === RegistrationStep::City) {
            if (isset($context['province_id'])) {
                $provinceId = (int) $context['province_id'];
                if ($text === __('Provinces')) {
                    return 'province_page_0';
                }
                foreach (array_slice(self::cities($provinceId, $page), 0, 12) as $city) {
                    if ($text === Presentation::label($city->name)) {
                        return 'city_'.$city->id;
                    }
                }

                return self::pageChoice($text, $page, 'city_page_'.$provinceId.'_');
            }
            foreach (array_slice(self::provinces($page), 0, 12) as $province) {
                if ($text === Presentation::label($province->name)) {
                    return 'province_'.$province->id;
                }
            }

            return self::pageChoice($text, $page, 'province_page_');
        }
        if ($state->step === RegistrationStep::Interests) {
            if ($text === __('Confirm interests')) {
                return 'done';
            }
            foreach (Interest::orderBy('name')->get() as $interest) {
                $label = Presentation::label($interest->name);
                if ($text === $label || $text === 'أ¢إ“â€œ '.$label) {
                    return 'interest_'.$interest->id;
                }
            }
        }
        if ($state->step === RegistrationStep::Preview) {
            return match ($text) {
                __('Confirm') => 'confirm', __('Edit profile') => 'edit_name', default => null,
            };
        }

        return null;
    }

    private static function pageChoice(string $text, int $page, string $prefix): ?string
    {
        if ($text === __('Previous') && $page > 0) {
            return $prefix.($page - 1);
        }
        if ($text === __('Next')) {
            return $prefix.($page + 1);
        }

        return null;
    }

    public static function profileActions(int $revision, int $id, string $back, ?int $activeConversationId = null, bool $isCurrentPartner = false): array
    {
        $chat = $isCurrentPartner && $activeConversationId
            ? self::button('s', $revision, __('Return to chat'), 'open_'.$activeConversationId)
            : self::button('s', $revision, __('Request chat'), 'request_'.$id);
        $rows = [
            [$chat, self::button('s', $revision, __('Send direct'), 'direct_'.$id)],
            [self::button('d', $revision, __('Add to contacts'), 'add_contact_'.$id), self::button('d', $revision, __('Invite to game'), 'profile_game_'.$id)],
            [self::button('d', $revision, __('More'), 'profile_more_'.$id), self::button('d', $revision, __('Back'), $back)],
        ];
        if ($activeConversationId && ! $isCurrentPartner) {
            $rows[] = [self::button('s', $revision, __('Return to chat'), 'open_'.$activeConversationId)];
        }

        return $rows;
    }

    public static function chatRequestDecision(int $revision, int $requestId): array
    {
        return [
            [
                self::button('s', $revision, __('Accept request'), 'accept_request_'.$requestId),
                self::button('s', $revision, __('Reject request'), 'reject_request_'.$requestId),
            ],
            [self::button('s', $revision, __('Back'), 'chats')],
        ];
    }

    public static function assertValidPayload(array $message): void
    {
        $markup = $message['parameters']['reply_markup'] ?? [];
        if (isset($markup['inline_keyboard'], $markup['keyboard'])) {
            throw new \InvalidArgumentException('Conflicting keyboard types.');
        }
        if (isset($markup['keyboard'])) {
            if (! is_array($markup['keyboard']) || ! array_is_list($markup['keyboard']) || $markup['keyboard'] === []) {
                throw new \InvalidArgumentException('Invalid reply keyboard rows.');
            }
            foreach ($markup['keyboard'] as $row) {
                if (! is_array($row) || ! array_is_list($row) || $row === []) {
                    throw new \InvalidArgumentException('Invalid reply keyboard row.');
                }
                foreach ($row as $button) {
                    $validString = is_string($button) && trim($button) !== '';
                    $validObject = is_array($button) && ! array_is_list($button)
                        && is_string($button['text'] ?? null) && trim($button['text']) !== ''
                        && (! isset($button['style']) || in_array($button['style'], ['success', 'danger', 'primary'], true));
                    if (! $validString && ! $validObject) {
                        throw new \InvalidArgumentException('Invalid reply keyboard button.');
                    }
                }
            }
        }
        $keyboard = $markup['inline_keyboard'] ?? null;
        if ($keyboard === null) {
            return;
        }
        if (! is_array($keyboard) || ! array_is_list($keyboard)) {
            throw new \InvalidArgumentException('Invalid inline keyboard rows.');
        }
        foreach ($keyboard as $row) {
            if (! is_array($row) || ! array_is_list($row) || $row === []) {
                throw new \InvalidArgumentException('Invalid inline keyboard row.');
            }
            foreach ($row as $button) {
                if (! is_array($button) || ! is_string($button['text'] ?? null) || trim($button['text']) === ''
                    || ! is_string($button['callback_data'] ?? null) || strlen($button['callback_data']) < 1
                    || strlen($button['callback_data']) > 64 || array_is_list($button)) {
                    throw new \InvalidArgumentException('Invalid inline keyboard button.');
                }
            }
        }
    }

    public static function button(string $scope, int $revision, string $label, string $action, ?string $style = null): array
    {
        $button = ['text' => $label, 'callback_data' => "{$scope}:{$revision}:{$action}"];
        if ($style !== null) {
            $button['style'] = $style;
        }

        return $button;
    }

    public static function grid(array $buttons, int $columns = 2): array
    {
        return array_chunk($buttons, $columns);
    }

    public static function navigation(string $scope, int $revision, string $back, ?string $cancel = null): array
    {
        $row = [self::button($scope, $revision, __('Back'), $back)];
        if ($cancel !== null) {
            $row[] = self::button($scope, $revision, __('Cancel'), $cancel);
        }

        return [$row];
    }

    public static function pagination(string $scope, int $revision, int $page, bool $more, string $prefix, int $firstPage = 0): array
    {
        $row = [];
        if ($page > $firstPage) {
            $row[] = self::button($scope, $revision, __('Previous'), $prefix.($page - 1));
        }
        if ($more) {
            $row[] = self::button($scope, $revision, __('Next'), $prefix.($page + 1));
        }

        return $row ? [$row] : [];
    }

    public static function ages(int $revision, int $page): array
    {
        $page = max(0, min(7, $page));
        $buttons = [];
        foreach (range(18 + $page * 8, min(80, 25 + $page * 8)) as $age) {
            $buttons[] = self::button('r', $revision, (string) $age, 'age_'.$age);
        }

        return array_merge(self::grid($buttons, 4), self::pagination('r', $revision, $page, 18 + ($page + 1) * 8 <= 80, 'age_page_'));
    }

    public static function gender(int $revision): array
    {
        return [
            [self::button('r', $revision, __('Man'), 'male')],
            [self::button('r', $revision, __('Woman'), 'female')],
        ];
    }

    public static function eventDayOptions(int $page = 0): array
    {
        $today = CarbonImmutable::today(config('presentation.timezone'));
        $options = [];
        if ($page === 0) {
            $options[__('Today')] = $today->format('Ymd');
            $options[__('Tomorrow')] = $today->addDay()->format('Ymd');
            foreach ([6, 0, 1, 2, 3, 4, 5] as $weekday) {
                for ($offset = 1; $offset <= 7; $offset++) {
                    $day = $today->addDays($offset);
                    if ($day->dayOfWeek === $weekday) {
                        $options[$day->locale('fa')->translatedFormat('l')] = $day->format('Ymd');
                        break;
                    }
                }
            }

            return $options;
        }
        $first = 8 + ($page - 1) * 10;
        for ($offset = $first; $offset <= min(30, $first + 9); $offset++) {
            $day = $today->addDays($offset);
            $options[$day->locale('fa')->translatedFormat('l j F')] = $day->format('Ymd');
        }

        return $options;
    }

    public static function eventDaysReply(int $page = 0): array
    {
        $options = self::eventDayOptions($page);
        $rows = $page === 0
            ? array_merge(self::grid(array_slice(array_keys($options), 0, 2), 2), self::grid(array_slice(array_keys($options), 2), 3))
            : self::grid(array_keys($options), 2);
        if ($page === 0) {
            $rows[] = [__('Other date')];
        } else {
            $rows = array_merge($rows, self::replyPage($page, $page < 3));
        }
        $rows[] = [__('Back'), __('Cancel')];

        return $rows;
    }

    public static function eventTimesReply(): array
    {
        $rows = self::grid(['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00', '22:00'], 3);
        $rows[] = [__('Other time')];
        $rows[] = [__('Back'), __('Cancel')];

        return $rows;
    }

    public static function capacityReply(): array
    {
        return [
            [__(':count people', ['count' => 4]), __(':count people', ['count' => 6]), __(':count people', ['count' => 10])],
            [__(':count people', ['count' => 15]), __(':count people', ['count' => 20])],
            [__('Unlimited'), __('Other capacity')],
            [__('Back'), __('Cancel')],
        ];
    }

    public static function eventAction(string $text, array $context): ?string
    {
        if ($text === __('Back')) {
            return 'event_step_back';
        }
        if ($text === __('Cancel')) {
            return 'event_cancel_wizard';
        }
        $step = $context['step'] ?? null;
        if ($step === 'day' || $step === 'date') {
            $page = max(0, min(3, (int) ($context['day_page'] ?? 0)));
            foreach (self::eventDayOptions($page) as $label => $date) {
                if ($text === $label) {
                    return 'event_day_'.$date;
                }
            }
            if ($text === __('Other date') && $page === 0) {
                return 'event_day_page_1';
            }
            if ($text === __('Previous') && $page > 0) {
                return 'event_day_page_'.($page - 1);
            }
            if ($text === __('Next') && $page > 0 && $page < 3) {
                return 'event_day_page_'.($page + 1);
            }
        }
        if ($step === 'time') {
            if (in_array($text, ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00', '22:00'], true)) {
                return 'event_time_'.str_replace(':', '', $text);
            }
            if ($text === __('Other time')) {
                return 'event_time_custom';
            }
        }
        if ($step === 'capacity') {
            foreach ([4, 6, 10, 15, 20] as $count) {
                if ($text === __(':count people', ['count' => $count])) {
                    return 'event_capacity_'.$count;
                }
            }
            if ($text === __('Unlimited')) {
                return 'event_capacity_0';
            }
            if ($text === __('Other capacity')) {
                return 'event_capacity_custom';
            }
        }

        return null;
    }

    public static function eventDays(int $revision, int $page = 0): array
    {
        $today = CarbonImmutable::today(config('presentation.timezone'));
        $buttons = [];
        for ($i = $page * 8; $i < min(($page + 1) * 8, 30); $i++) {
            $day = $today->addDays($i);
            $label = $i === 0 ? __('Today') : ($i === 1 ? __('Tomorrow') : $day->translatedFormat('l j F'));
            $buttons[] = self::button('d', $revision, $label, 'event_day_'.$day->format('Ymd'));
        }

        return array_merge(self::grid($buttons), self::pagination('d', $revision, $page, ($page + 1) * 8 < 30, 'event_day_page_'));
    }

    public static function eventTimes(int $revision): array
    {
        $buttons = [];
        foreach (['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00', '22:00'] as $time) {
            $buttons[] = self::button('d', $revision, $time, 'event_time_'.str_replace(':', '', $time));
        }

        return array_merge(self::grid($buttons, 4), [[self::button('d', $revision, __('Other time'), 'event_time_custom')]]);
    }

    public static function capacities(int $revision): array
    {
        $buttons = [];
        foreach ([4, 6, 10, 15, 20] as $count) {
            $buttons[] = self::button('d', $revision, __(':count people', ['count' => $count]), 'event_capacity_'.$count);
        }

        return array_merge(self::grid($buttons, 3), [
            [self::button('d', $revision, __('Unlimited'), 'event_capacity_0')],
            [self::button('d', $revision, __('Other capacity'), 'event_capacity_custom')],
        ]);
    }
}
