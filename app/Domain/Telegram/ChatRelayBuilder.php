<?php

namespace App\Domain\Telegram;

use App\Domain\Chat\ChatMessage;
use App\Domain\Chat\MessageType;
use App\Domain\Users\User;

class ChatRelayBuilder
{
    public function build(User $recipient, ChatMessage $message): array
    {
        $parameters = ['chat_id' => $recipient->telegram_user_id];
        if ($message->is_protected) {
            $parameters['protect_content'] = true;
        }

        return match ($message->message_type) {
            MessageType::Text => ['method' => 'sendMessage', 'parameters' => $parameters + ['text' => $message->text]],
            MessageType::Photo => ['method' => 'sendPhoto', 'parameters' => $parameters + ['photo' => $message->telegram_file_id]],
            MessageType::Voice => ['method' => 'sendVoice', 'parameters' => $parameters + ['voice' => $message->telegram_file_id]],
        };
    }
}
