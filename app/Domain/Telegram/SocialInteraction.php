<?php

namespace App\Domain\Telegram;

use App\Domain\Chat\ChatRequest;
use App\Domain\Chat\ChatRequestService;
use App\Domain\Chat\Conversation;
use App\Domain\Chat\ConversationCleanupService;
use App\Domain\Chat\ConversationService;
use App\Domain\Chat\ConversationStatus;
use App\Domain\Chat\ConversationTelegramMessage;
use App\Domain\Chat\MessageType;
use App\Domain\Direct\DirectMessage;
use App\Domain\Direct\DirectMessageService;
use App\Domain\Moderation\ContactInformationGuard;
use App\Domain\Moderation\ReportReason;
use App\Domain\Moderation\ReportService;
use App\Domain\Payments\CoinPackage;
use App\Domain\Payments\CoinTransactionType;
use App\Domain\Payments\FeaturePricingService;
use App\Domain\Payments\IdentityShareService;
use App\Domain\Payments\InsufficientCoinsException;
use App\Domain\Payments\PaidFeature;
use App\Domain\Payments\TelegramIdentityShareRequest;
use App\Domain\Payments\WalletService;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Users\BlockService;
use App\Domain\Users\MitoId;
use App\Domain\Users\User;
use App\Support\Presentation;
use Illuminate\Support\Facades\DB;

class SocialInteraction
{
    public function __construct(private readonly ChatRequestService $requests, private readonly ConversationService $conversations, private readonly DirectMessageService $direct, private readonly BlockService $blocks, private readonly ReportService $reports, private readonly ContactInformationGuard $guard, private readonly SocialNotificationService $notifications, private readonly WalletService $wallets, private readonly IdentityShareService $identityShares, private readonly DiscoveryInteraction $discovery, private readonly ConversationCleanupService $cleanup) {}

    public function handle(User $user, IncomingUpdate $update, InteractionState $state): array
    {
        $callback = $update->callback();
        $value = $callback ? $this->callbackValue($callback, $state) : null;
        if ($callback === null && in_array($state->mode, ['chat', 'direct'], true)) {
            $value ??= Keyboard::chatAction(Presentation::input(trim($update->input(null)->text ?? '')));
        }
        if ($value === 'chats') {
            return $this->menu($state);
        }
        if ($value === 'back' || Presentation::input(trim($update->input(null)->text ?? '')) === 'Chats') {
            $state->update(['mode' => 'menu', 'conversation_id' => null, 'direct_recipient_id' => null]);

            return $this->menu($state);
        }
        if ($value && str_starts_with($value, 'view_request_')) {
            return $this->viewRequest($user, $state, (int) substr($value, 13));
        }
        if ($value && str_starts_with($value, 'request_')) {
            return $this->request($user, (int) substr($value, 8));
        }
        if ($value && str_starts_with($value, 'accept_request_')) {
            return $this->acceptRequest($user, $state, (int) substr($value, 15));
        }
        if ($value && str_starts_with($value, 'reject_request_')) {
            return $this->rejectRequest($user, $state, (int) substr($value, 15));
        }
        if ($value && preg_match('/^direct_([1-9][0-9]*)$/D', $value, $directMatch)) {
            $recipientId = (int) $directMatch[1];
            $active = $this->conversations->activeFor($user);
            $state->update([
                'mode' => 'direct_compose',
                'direct_recipient_id' => $recipientId,
                'direct_context' => [
                    'recipient_id' => $recipientId,
                    'key' => 'direct:'.bin2hex(random_bytes(12)),
                    'return' => $active ? 'chat' : 'profile',
                    'conversation_id' => $active?->id,
                ],
            ]);

            return $this->directComposeScreen($state);
        }
        if ($value && str_starts_with($value, 'block_')) {
            $target = User::findOrFail((int) substr($value, 6));
            $this->blocks->block($user, $target);
            $state->update(['mode' => 'menu']);

            return $this->message(__('User blocked.'));
        }
        if ($value && str_starts_with($value, 'report_')) {
            $target = User::findOrFail((int) substr($value, 7));
            $this->reports->create($user, $target, ReportReason::Other);

            return $this->message(__('Report submitted.'), [
                [$this->button($state, __('Block this user'), 'block_'.$target->id)],
                [$this->button($state, __('Back'), 'back')],
            ]);
        }
        if ($value === 'direct_edit') {
            $context = $state->direct_context ?? [];
            if (! isset($context['recipient_id'])) {
                return $this->message(__('This direct draft is no longer available.'));
            }
            unset($context['draft']);
            $state->update(['mode' => 'direct_compose', 'direct_context' => $context]);

            return $this->directComposeScreen($state, true);
        }
        if ($value === 'direct_cancel') {
            return $this->cancelDirect($user, $state);
        }
        if ($value === 'direct_send') {
            return $this->confirmDirect($user, $state);
        }
        if ($value && str_starts_with($value, 'direct_reply_')) {
            return $this->replyDirect($user, $state, (int) substr($value, 13));
        }
        if ($value && str_starts_with($value, 'open_direct_')) {
            return $this->openDirect($user, $state, (int) substr($value, 12));
        }
        if ($value && str_starts_with($value, 'open_')) {
            return $this->openConversation($user, $state, (int) substr($value, 5));
        }
        if ($value === 'share_id') {
            if (! $state->conversation_id) {
                return $this->message(__('Open an active chat to share Telegram IDs.'));
            }
            $conversation = Conversation::findOrFail($state->conversation_id);
            $other = $conversation->participants()->where('users.id', '<>', $user->id)->firstOrFail();
            $request = $this->identityShares->request($user, $other, $conversation->id);
            $this->notifications->queue($other, 'identity_share', $request->id, __('A user wants to share Telegram usernames with you.'), 'identity_share:'.$request->id);

            return $this->message(__('Share request sent.'));
        }
        if ($value && str_starts_with($value, 'accept_share_')) {
            $request = TelegramIdentityShareRequest::findOrFail((int) substr($value, 13));
            [$accepted, $requesterUsername, $recipientUsername] = $this->identityShares->accept($user, $request);
            $this->notifications->queue($accepted->requester, 'identity_shared', $accepted->id, __('Telegram ID shared successfully: @').$recipientUsername, 'identity_shared:'.$accepted->id.':requester');

            return $this->message(__('Telegram ID shared successfully: @').$requesterUsername);
        }
        if ($value && str_starts_with($value, 'reject_share_')) {
            $request = TelegramIdentityShareRequest::findOrFail((int) substr($value, 13));
            if ($request->recipient_user_id !== $user->id) {
                throw new \DomainException('Only the recipient can reject.');
            }
            $request->update(['status' => 'rejected', 'rejected_at' => now()]);

            return $this->message(__('Share request rejected.'));
        }
        if ($value === 'wallet' || Presentation::input(trim($update->input(null)->text ?? '')) === 'Wallet / Coins') {
            return $this->wallet($user, $state);
        }
        if ($value === 'test_credit') {
            if (! config('economy.wallet_test_credit_enabled')) {
                throw new \DomainException('Test credit is unavailable.');
            }
            $amount = (int) config('economy.wallet_test_credit_amount', 100);
            $transaction = $this->wallets->credit($user, $amount, CoinTransactionType::TestCredit, 'test_credit', [
                'idempotency_key' => 'test_credit:update:'.($update->data['update_id'] ?? 0),
                'description' => __('Test credit'),
            ]);

            return array_merge(
                $this->message(__('Test coins were added. New balance: :coins coins', ['coins' => $transaction->balance_after])),
                $this->wallet($user, $state),
            );
        }
        if ($value === 'transactions') {
            $items = $this->wallets->wallet($user)->transactions()->latest()->limit(20)->get();
            $text = __("Transaction history\n");
            foreach ($items as $item) {
                $text .= ($item->amount > 0 ? '+' : '').$item->amount.' '.($item->description ?: Presentation::label($item->code))."\n";
            }

            return $this->message($text, [[$this->button($state, __('Back'), 'wallet')]]);
        }
        if ($value === 'buy_coins') {
            $packages = CoinPackage::where('is_active', true)->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })->orderBy('sort_order')->get();
            $text = __("Buy Coins\nOnline payment will be enabled later.\n");
            foreach ($packages as $package) {
                $text .= Presentation::label($package->name).': '.$package->base_coins.__(' coins').($package->bonus_coins ? ' + '.$package->bonus_coins.__(' bonus') : '').' ط·آ£ط¢آ¢ط£آ¢أ¢â‚¬ع‘ط¢آ¬ط£آ¢أ¢â€ڑآ¬أ¢â‚¬إ’ '.$package->price_amount.' '.Presentation::label($package->currency)."\n";
            }

            return $this->message($text, [[$this->button($state, __('Back'), 'wallet')]]);
        }
        if ($value === 'chat_partner_profile') {
            $conversation = $this->activeConversation($user, $state);
            $other = $conversation->participants()->where('users.id', '<>', $user->id)->firstOrFail();

            return $this->discovery->chatPartnerProfile($other, $conversation->id, $state->revision + 1);
        }
        if ($value === 'chat_direct') {
            $conversation = $this->activeConversation($user, $state);
            $other = $conversation->participants()->where('users.id', '<>', $user->id)->firstOrFail();
            $state->update(['mode' => 'direct_compose', 'conversation_id' => $conversation->id, 'direct_recipient_id' => $other->id, 'direct_context' => ['recipient_id' => $other->id, 'key' => 'direct:'.bin2hex(random_bytes(12)), 'return' => 'chat', 'conversation_id' => $conversation->id]]);

            return $this->directComposeScreen($state);
        }
        if (in_array($value, ['chat_protect_on', 'chat_protect_off'], true)) {
            $conversation = $this->activeConversation($user, $state);
            $enabled = $value === 'chat_protect_on';
            $conversation = $this->conversations->setProtected($user, $conversation, $enabled);
            $other = $conversation->participants()->where('users.id', '<>', $user->id)->firstOrFail();
            $text = $enabled ? __('Private chat enabled.') : __('Private chat disabled.');
            $key = 'chat_protection:'.$conversation->id.':'.($enabled ? 'on' : 'off').':'.$conversation->updated_at->getTimestamp();
            $this->notifications->queueReply($other, 'chat_protection', $conversation->id, $text, Keyboard::chatReply($enabled), $key);

            return $this->messageReply($text, Keyboard::chatReply($enabled));
        }
        if ($value && str_starts_with($value, 'cleanup_chat_')) {
            $conversation = Conversation::findOrFail((int) substr($value, 13));
            $status = $this->cleanup->request($user, $conversation);

            return $this->message($status === 'completed'
                ? __('Conversation messages were already cleaned up.')
                : __('Cleaning up conversation messages...'));
        }
        if ($value && str_starts_with($value, 'cancel_cleanup_chat_')) {
            return $this->message(__('Cleanup cancelled.'));
        }
        if ($value && str_starts_with($value, 'confirm_cleanup_chat_')) {
            $conversation = Conversation::findOrFail((int) substr($value, 21));
            $status = $this->cleanup->request($user, $conversation);

            return $this->message($status === 'completed'
                ? __('Conversation messages were already cleaned up.')
                : __('Cleaning up conversation messages...'));
        }
        if ($value === 'close_chat') {
            $conversation = $this->activeConversation($user, $state);

            return $this->message(__('Are you sure you want to end this chat?'), [
                [$this->button($state, __('Yes, end chat'), 'confirm_close_chat_'.$conversation->id, 'danger')],
                [$this->button($state, __('No, keep chatting'), 'cancel_close_chat_'.$conversation->id)],
            ]);
        }
        if ($value && str_starts_with($value, 'cancel_close_chat_')) {
            $conversation = Conversation::findOrFail((int) substr($value, 18));
            if (! $conversation->participants()->whereKey($user->id)->exists()) {
                throw new \DomainException('Not a participant.');
            }
            if ($conversation->status !== ConversationStatus::Active) {
                return $this->messageReply(__('This chat already ended.'), Keyboard::homeReply());
            }
            $state->update(['mode' => 'chat', 'conversation_id' => $conversation->id]);

            return $this->messageReply(__('The chat continues.'), Keyboard::chatReply($conversation->is_protected));
        }
        if ($value && str_starts_with($value, 'confirm_close_chat_')) {
            $conversation = Conversation::findOrFail((int) substr($value, 19));
            $result = $this->conversations->endConversation($user, $conversation);
            if (! $result['ended']) {
                return $this->messageReply(__('This chat already ended.'), Keyboard::homeReply());
            }
            $participants = $result['participants']->keyBy('id');
            foreach ($participants as $participant) {
                $other = $participants->firstWhere('id', '<>', $participant->id);
                $this->notifications->chatEnded(
                    $participant,
                    $conversation->id,
                    $other,
                    $result['event_key'],
                );
            }

            return [];
        }
        if (in_array($state->mode, ['direct', 'direct_compose'], true) && $state->direct_recipient_id) {
            $text = trim($update->input(null)->text ?? '');
            if ($text === '' || $this->guard->blocked($text)) {
                return $this->message(__('This direct message is not allowed. No coins were charged.'));
            }
            $context = $state->direct_context ?? [
                'recipient_id' => $state->direct_recipient_id,
                'key' => 'direct:'.bin2hex(random_bytes(12)),
                'return' => $this->conversations->activeFor($user) ? 'chat' : 'profile',
                'conversation_id' => $this->conversations->activeFor($user)?->id,
            ];
            $context['draft'] = $text;
            $state->update(['mode' => 'direct_review', 'direct_context' => $context]);

            $price = app(FeaturePricingService::class)->cost(PaidFeature::DirectMessage) ?? 0;

            return $this->message(__("Your message:\n\n:message", ['message' => $text])."\n\n".__('Direct send cost', ['coins' => Presentation::persianDigits((string) $price)]), [
                [$this->button($state, __('Send'), 'direct_send', 'success'), $this->button($state, __('Edit'), 'direct_edit')],
                [$this->button($state, __('Cancel'), 'direct_cancel')],
            ]);
        }
        if ($state->mode === 'chat' && $state->conversation_id) {
            $conversation = Conversation::findOrFail($state->conversation_id);
            $input = $update->input(null);
            $type = $input->photo ? MessageType::Photo : ($input->voice ? MessageType::Voice : MessageType::Text);
            $message = $this->conversations->send($user, $conversation, $type, $input->text, $input->photo ?? $input->voice, $update->data['update_id'] ?? null);
            $other = $conversation->participants()->where('users.id', '<>', $user->id)->firstOrFail();
            $incomingMessageId = $update->data['message']['message_id'] ?? null;
            if ($incomingMessageId) {
                ConversationTelegramMessage::firstOrCreate(
                    ['telegram_chat_id' => $user->telegram_user_id, 'telegram_message_id' => $incomingMessageId],
                    ['conversation_id' => $conversation->id, 'chat_message_id' => $message->id, 'user_id' => $user->id, 'direction' => 'incoming']
                );
            }
            $this->notifications->chatMessage($other, $message);

            return [];
        }

        return $this->menu($state);
    }

    public function menu(InteractionState $state): array
    {
        $active = $this->conversations->activeFor($state->user);
        if ($active) {
            return $this->openConversation($state->user, $state, $active->id);
        }

        $state->update(['mode' => 'menu', 'conversation_id' => null]);

        return $this->message(__('No active chat right now.'), [
            [Keyboard::button('d', $state->revision + 1, __('Find people'), 'search')],
            [Keyboard::button('d', $state->revision + 1, __('Home'), 'menu')],
        ]);
    }

    private function request(User $user, int $targetId): array
    {
        $target = User::findOrFail($targetId);
        $request = $this->requests->create($user, $target);
        $this->notifications->request($target, $request->id);

        return $this->message(__('Chat request sent. Waiting for a reply.'));
    }

    private function viewRequest(User $user, InteractionState $state, int $id): array
    {
        $request = ChatRequest::with('requester')->findOrFail($id);
        [$request, $firstView] = DB::transaction(function () use ($user, $request) {
            [$viewed, $firstView] = $this->requests->view($user, $request);
            if ($firstView) {
                $this->notifications->seen($viewed->requester, $viewed->id, $user);
            }

            return [$viewed, $firstView];
        });
        $state->update(['mode' => 'request_review']);

        return $this->discovery->requestProfile($request->requester, $request->id, $state->revision + 1);
    }

    private function acceptRequest(User $user, InteractionState $state, int $id): array
    {
        $request = ChatRequest::with('requester')->findOrFail($id);
        try {
            $conversation = $this->requests->accept($user, $request);
        } catch (InsufficientCoinsException $e) {
            $this->notifications->queue(
                $request->requester,
                'chat_request_payment_required',
                $request->id,
                __('You need more coins before this chat request can be accepted.'),
                'chat_request_payment_required:'.$request->id,
            );

            throw $e;
        }
        InteractionState::whereIn('user_id', [$request->requester_user_id, $user->id])->update([
            'mode' => 'chat',
            'conversation_id' => $conversation->id,
            'direct_recipient_id' => null,
            'updated_at' => now(),
        ]);
        $state->refresh();
        $this->notifications->accepted($request->requester, $request->id, $user);

        return $this->messageReply(__('Chat connected. Send your message here.'), Keyboard::chatReply(false));
    }

    private function rejectRequest(User $user, InteractionState $state, int $id): array
    {
        $request = ChatRequest::with('requester')->findOrFail($id);
        $this->requests->reject($user, $request);
        $state->update(['mode' => 'menu']);
        $this->notifications->rejected($request->requester, $request->id, $user);

        return $this->message(__('Chat request rejected.'));
    }

    private function confirmDirect(User $user, InteractionState $state): array
    {
        $context = $state->direct_context ?? [];
        if (isset($context['sent_message_id'])) {
            $active = $this->conversations->activeFor($user);

            return $active ? $this->messageReply(__('Direct message sent.'), Keyboard::chatReply($active->is_protected)) : $this->message(__('Direct message sent.'));
        }
        if ($state->mode !== 'direct_review' || empty($context['draft']) || empty($context['recipient_id']) || empty($context['key'])) {
            return $this->message(__('This direct draft is no longer available.'));
        }
        $recipient = User::findOrFail((int) $context['recipient_id']);
        $message = $this->direct->send($user, $recipient, (string) $context['draft'], (string) $context['key']);
        $this->notifications->directMessage($recipient, $message);
        $context['sent_message_id'] = $message->id;
        $active = $this->conversations->activeFor($user);
        $state->update(['mode' => $active ? 'chat' : 'menu', 'conversation_id' => $active?->id, 'direct_recipient_id' => null, 'direct_context' => $context]);
        if ($active) {
            return $this->messageReply(__('Direct message sent.'), Keyboard::chatReply($active->is_protected));
        }

        return array_merge($this->message(__('Direct message sent.')), $this->discovery->lookupProfile($user, $recipient->public_mito_id, RegistrationState::where('user_id', $user->id)->firstOrFail()));
    }

    private function openDirect(User $user, InteractionState $state, int $id): array
    {
        $message = DirectMessage::with(['sender.profile', 'recipient'])->findOrFail($id);
        [$message, $first] = $this->direct->markSeenWithStatus($user, $message);
        if ($first) {
            $this->notifications->directSeen($message->sender, $message, $user);
        }
        $sender = $message->sender;
        $name = $sender->profile?->display_name;
        $text = __('Direct message from:')."\n".MitoId::display($sender->public_mito_id);
        if ($name) {
            $text .= "\n".$name;
        }
        $text .= "\n\n".__('Message:')."\n".$message->text;

        return $this->message($text, [
            [$this->button($state, __('Reply'), 'direct_reply_'.$message->id, 'success')],
            [Keyboard::button('d', $state->revision + 1, __('View sender profile'), 'profile_'.$sender->id.'_menu')],
            [$this->button($state, __('Back'), 'back')],
        ]);
    }

    private function directComposeScreen(InteractionState $state, bool $editing = false): array
    {
        $price = app(FeaturePricingService::class)->cost(PaidFeature::DirectMessage) ?? 0;
        $text = ($editing ? __('Type the revised message.') : __('Type your direct message.'))
            ."\n\n".__('Direct send cost', ['coins' => Presentation::persianDigits((string) $price)]);

        return $this->message($text, [
            [$this->button($state, __('Cancel'), 'direct_cancel')],
        ]);
    }

    private function replyDirect(User $user, InteractionState $state, int $id): array
    {
        $message = DirectMessage::findOrFail($id);
        if ($message->recipient_user_id !== $user->id) {
            throw new \DomainException('Only the recipient can view this direct message.');
        }
        $active = $this->conversations->activeFor($user);
        $state->update([
            'mode' => 'direct_compose',
            'direct_recipient_id' => $message->sender_user_id,
            'direct_context' => [
                'recipient_id' => $message->sender_user_id,
                'key' => 'direct:'.bin2hex(random_bytes(12)),
                'return' => $active ? 'chat' : 'direct_read',
                'conversation_id' => $active?->id,
                'source_direct_id' => $message->id,
            ],
        ]);

        return $this->directComposeScreen($state);
    }

    private function cancelDirect(User $user, InteractionState $state): array
    {
        $context = $state->direct_context ?? [];
        $return = $context['return'] ?? 'menu';
        $active = $this->conversations->activeFor($user);
        $state->update(['mode' => $active ? 'chat' : 'menu', 'conversation_id' => $active?->id, 'direct_recipient_id' => null, 'direct_context' => null]);
        if ($active) {
            return $this->messageReply(__('Direct compose cancelled.'), Keyboard::chatReply($active->is_protected));
        }
        if ($return === 'direct_read' && ! empty($context['source_direct_id'])) {
            return array_merge($this->message(__('Direct compose cancelled.')), $this->openDirect($user, $state, (int) $context['source_direct_id']));
        }
        if ($return === 'profile' && ! empty($context['recipient_id'])) {
            $recipient = User::find((int) $context['recipient_id']);
            if ($recipient) {
                return array_merge($this->message(__('Direct compose cancelled.')), $this->discovery->lookupProfile($user, $recipient->public_mito_id, RegistrationState::where('user_id', $user->id)->firstOrFail()));
            }
        }

        return $this->messageReply(__('Direct compose cancelled.'), Keyboard::homeReply());
    }

    private function openConversation(User $user, InteractionState $state, int $id): array
    {
        $conversation = Conversation::findOrFail($id);
        $this->conversations->markRead($user, $conversation);
        $state->update(['mode' => 'chat', 'conversation_id' => $id]);
        $messages = $conversation->messages()->latest('sent_at')->limit(20)->get()->reverse();
        $text = __('Chat mode. Use Back to leave.')."\n";
        foreach ($messages as $m) {
            $text .= ($m->sender_user_id === $user->id ? __('You') : __('Them')).': '.($m->text ?? '['.Presentation::label($m->message_type->value).']').'\n';
        }

        return $this->messageReply($text, Keyboard::chatReply($conversation->is_protected));
    }

    private function activeConversation(User $user, InteractionState $state): Conversation
    {
        $conversation = $this->conversations->activeFor($user);
        if (! $conversation) {
            $state->update(['mode' => 'menu', 'conversation_id' => null, 'direct_recipient_id' => null]);
            throw new \DomainException('Conversation is unavailable.');
        }
        if ($state->conversation_id !== $conversation->id || $state->mode !== 'chat') {
            $state->update(['mode' => 'chat', 'conversation_id' => $conversation->id]);
        }

        return $conversation;
    }

    private function messageReply(string $text, array $rows): array
    {
        return [['method' => 'sendMessage', 'parameters' => [
            'text' => $text,
            'reply_markup' => Keyboard::reply($rows, true),
        ]]];
    }

    private function message(string $text, array $keyboard = []): array
    {
        $p = ['text' => $text];
        if ($keyboard) {
            $p['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        return [['method' => 'sendMessage', 'parameters' => $p]];
    }

    private function button(InteractionState $state, string $label, string $value, ?string $style = null): array
    {
        return Keyboard::button('s', $state->revision + 1, $label, $value, $style);
    }

    private function wallet(User $user, InteractionState $state): array
    {
        $rows = [[$this->button($state, __('Buy coins'), 'buy_coins')]];
        if (config('economy.wallet_test_credit_enabled')) {
            $rows[0][] = $this->button($state, __('Add 100 test coins'), 'test_credit');
        }
        $rows[] = [$this->button($state, __('Transactions'), 'transactions')];
        $rows[] = [Keyboard::button('d', $state->revision + 1, __('Gold membership'), 'gold')];
        $rows[] = [Keyboard::button('d', $state->revision + 1, __('Back'), 'menu')];

        return $this->message(__('Your balance: :coins coins', ['coins' => $this->wallets->wallet($user)->balance]), $rows);
    }

    private function callbackValue(string $callback, InteractionState $state): ?string
    {
        $parts = explode(':', $callback, 3);
        if (count($parts) !== 3) {
            return null;
        }
        if ($parts[0] === 'n' && $parts[1] === '0' && (str_starts_with($parts[2], 'view_request_') || str_starts_with($parts[2], 'open_direct_') || str_starts_with($parts[2], 'cleanup_chat_'))) {
            return $parts[2];
        }

        if ($parts[0] === 's' && preg_match('/^(confirm|cancel)_close_chat_[1-9][0-9]*$/D', $parts[2])) {
            return $parts[2];
        }

        if ($parts[0] === 's' && in_array($parts[2], ['direct_send', 'direct_edit', 'direct_cancel'], true) && in_array($state->mode, ['direct_review', 'direct_compose'], true)) {
            return $parts[2];
        }

        return $parts[0] === 's' && (int) $parts[1] === $state->revision ? $parts[2] : null;
    }
}
