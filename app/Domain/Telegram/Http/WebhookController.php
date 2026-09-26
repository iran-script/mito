<?php

namespace App\Domain\Telegram\Http;

use App\Domain\Telegram\Actions\AcceptUpdate;
use Illuminate\Http\JsonResponse;

class WebhookController
{
    public function __invoke(WebhookRequest $request, AcceptUpdate $accept): JsonResponse
    {
        $data = $request->validated();
        if (isset($data['message']) || isset($data['callback_query'])) {
            $accept->execute($data);
        }

        return response()->json(['ok' => true]);
    }
}
