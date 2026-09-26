<?php

namespace App\Domain\Telegram\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class WebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $secret = config('telegram.webhook_secret');

        return is_string($secret) && $secret !== '' && hash_equals($secret, (string) $this->header('X-Telegram-Bot-Api-Secret-Token'));
    }

    public function rules(): array
    {
        $rules = ['update_id' => 'required|integer|min:0|max:9007199254740991', 'message' => 'sometimes|required|array', 'callback_query' => 'sometimes|required|array'];
        foreach (['message', 'callback_query.message'] as $prefix) {
            $rules[$prefix.'.chat.id'] = 'required_with:'.$prefix.'|integer|min:1|max:9007199254740991';
            $rules[$prefix.'.chat.type'] = 'required_with:'.$prefix.'|in:private';
            $rules[$prefix.'.message_id'] = 'required_with:'.$prefix.'|integer|min:1';
        }
        foreach (['message.from', 'callback_query.from'] as $prefix) {
            $root = str_starts_with($prefix, 'message') ? 'message' : 'callback_query';
            $rules[$prefix.'.id'] = 'required_with:'.$root.'|integer|min:1|max:9007199254740991';
            $rules[$prefix.'.is_bot'] = 'exclude_without:'.$root.'|required|boolean|declined';
            $rules[$prefix.'.first_name'] = 'required_with:'.$root.'|string|max:255';
            $rules[$prefix.'.last_name'] = 'nullable|string|max:255';
            $rules[$prefix.'.username'] = 'nullable|string|max:255';
            $rules[$prefix.'.language_code'] = 'nullable|string|max:35';
        }

        return $rules + [
            'message.text' => 'sometimes|string|max:4096',
            'message.photo' => 'sometimes|array|min:1|max:10',
            'message.photo.*.file_id' => 'required|string|max:512',
            'message.voice' => 'sometimes|array',
            'message.voice.file_id' => 'required_with:message.voice|string|max:512',
            'message.voice.duration' => 'required_with:message.voice|integer|min:0|max:32767',
            'message.location' => 'sometimes|array',
            'message.location.latitude' => 'required_with:message.location|numeric|between:-90,90',
            'message.location.longitude' => 'required_with:message.location|numeric|between:-180,180',
            'callback_query.id' => 'required_with:callback_query|string|max:128',
            'callback_query.data' => ['required_with:callback_query', 'string', 'max:64', 'regex:/^(r:[0-9]{1,10}:(male|female|age_([1-7][0-9]|80)|age_page_[0-7]|province_[1-9][0-9]{0,9}|province_page_[0-9]{1,3}|city_[1-9][0-9]{0,9}|city_page_[1-9][0-9]{0,9}_[0-9]{1,3}|photo_prompt|voice_prompt|interest_[1-9][0-9]{0,9}|skip|done|back|confirm|edit_(name|age|gender|city|photo|voice|interests))|d:[0-9]{1,10}:(menu|more|profile|notifications|settings|blocked|help|search|search_more|anonymous|nearby|city|age|new|interest|distance|events|games|match_[a-z_]+|profile_[a-z0-9_]+|add_contact_[1-9][0-9]{0,9}|game_[a-z0-9_]+|event_[a-z0-9_]+|contacts|gold|gold_benefits|gold_usage|bulk_[a-z0-9_]+|contact_[1-9][0-9]{0,9}|remove_contact_[1-9][0-9]{0,9}|back|anonymous_(male|female)|nearby_(male|female)|distance_(male|female)|city_(male|female)|age_(male|female)|new_(male|female)|interest_[1-9][0-9]{0,9}(_(male|female))?|distance_(05|510|1015|1520|020)_(male|female)|page_(city|age|new)_(male|female)_[1-9][0-9]{0,3}|page_interest_[1-9][0-9]{0,9}_(male|female)_[1-9][0-9]{0,3}|page_distance_(05|510|1015|1520|020)_(male|female)_[1-9][0-9]{0,3})|n:0:((view_request|open_direct|cleanup_chat)_[1-9][0-9]{0,9})|s:[0-9]{1,10}:(chats|wallet|transactions|buy_coins|test_credit|back|close_chat|confirm_close_chat_[1-9][0-9]{0,9}|cancel_close_chat_[1-9][0-9]{0,9}|share_id|accept_share_[1-9][0-9]{0,9}|reject_share_[1-9][0-9]{0,9}|request_[1-9][0-9]{0,9}|accept_request_[1-9][0-9]{0,9}|reject_request_[1-9][0-9]{0,9}|direct_[1-9][0-9]{0,9}|direct_(send|edit|cancel)|direct_reply_[1-9][0-9]{0,9}|confirm_cleanup_chat_[1-9][0-9]{0,9}|cancel_cleanup_chat_[1-9][0-9]{0,9}|block_[1-9][0-9]{0,9}|report_[1-9][0-9]{0,9}|open_direct_[1-9][0-9]{0,9}|open_[1-9][0-9]{0,9}))$/D'],
            'callback_query.message' => 'required_with:callback_query|array',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->has('message') && $this->has('callback_query')) {
                $validator->errors()->add('update', 'Ambiguous update.');
            }
            $prefix = $this->has('callback_query') ? 'callback_query' : 'message';
            $chat = $prefix === 'message' ? 'message.chat.id' : 'callback_query.message.chat.id';
            $senderId = $this->input($prefix.'.from.id');
            $chatId = $this->input($chat);
            if (is_scalar($senderId) && is_scalar($chatId) && (string) $senderId !== (string) $chatId) {
                $validator->errors()->add('chat', 'Private chat sender mismatch.');
            }
        }];
    }
}
