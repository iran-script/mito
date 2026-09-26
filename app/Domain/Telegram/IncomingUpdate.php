<?php

namespace App\Domain\Telegram;

use App\Domain\Profiles\RegistrationInput;
use Illuminate\Support\Arr;

final readonly class IncomingUpdate
{
    public function __construct(public array $data) {}

    public static function sanitized(array $data): array
    {
        $callback = isset($data['callback_query']);
        $root = $callback ? $data['callback_query'] : $data['message'];
        $from = Arr::only($root['from'], ['id', 'is_bot', 'first_name', 'last_name', 'username', 'language_code']);
        $message = $callback ? $root['message'] : $root;
        $clean = ['message_id' => $message['message_id'], 'chat' => Arr::only($message['chat'], ['id', 'type'])];
        if ($callback) {
            return ['update_id' => $data['update_id'], 'callback_query' => ['id' => $root['id'], 'from' => $from, 'message' => $clean, 'data' => $root['data']]];
        }
        $clean['from'] = $from;
        if (isset($message['text'])) {
            $clean['text'] = $message['text'];
        }
        if (isset($message['photo'])) {
            $clean['photo'] = array_map(fn ($photo) => ['file_id' => $photo['file_id']], $message['photo']);
        }
        if (isset($message['voice'])) {
            $clean['voice'] = Arr::only($message['voice'], ['file_id', 'duration']);
        }
        if (isset($message['location']) && ! isset($message['text'])) {
            $clean['location'] = Arr::only($message['location'], ['latitude', 'longitude']);
        }

        return ['update_id' => $data['update_id'], 'message' => $clean];
    }

    public function asAction(string $scope, int $revision, string $action): self
    {
        return new self([
            'update_id' => $this->data['update_id'] ?? 0,
            'callback_query' => [
                'data' => "{$scope}:{$revision}:{$action}",
                'from' => $this->sender(),
                'message' => $this->data['message'] ?? ($this->data['callback_query']['message'] ?? []),
            ],
        ]);
    }

    public function sender(): array
    {
        return $this->data['callback_query']['from'] ?? $this->data['message']['from'];
    }

    public function userId(): int
    {
        return (int) $this->sender()['id'];
    }

    public function callbackId(): ?string
    {
        return $this->data['callback_query']['id'] ?? null;
    }

    public function callback(): ?string
    {
        return $this->data['callback_query']['data'] ?? null;
    }

    public function input(?string $choice): RegistrationInput
    {
        $message = $this->data['message'] ?? [];
        $photos = $message['photo'] ?? [];

        return new RegistrationInput($message['text'] ?? null, $choice, $photos ? end($photos)['file_id'] : null, $message['voice']['file_id'] ?? null, $message['voice']['duration'] ?? null);
    }

    public function location(): ?array
    {
        return $this->data['message']['location'] ?? null;
    }
}
