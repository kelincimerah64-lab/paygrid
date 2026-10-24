<?php

namespace App\Services\BotTele;

/**
 * Port of n8n "Normalize Telegram": flattens a Telegram update (message or
 * callback_query) into the same field names the rest of the bot uses.
 * Missing values are '' (and has_photo false), as in the original.
 */
class UpdateNormalizer
{
    public function normalize(array $update): array
    {
        $out = [
            'event_type' => '',
            'chat_id' => '', 'chat_type' => '', 'chat_title' => '',
            'user_id' => '', 'username' => '', 'first_name' => '', 'last_name' => '',
            'text' => '',
            'callback_data' => '', 'callback_query_id' => '',
            'message_id' => '',
            'has_photo' => false, 'photo_file_id' => '', 'photo_file_unique_id' => '', 'photo_width' => '', 'photo_height' => '',
        ];

        if (is_array($message = $update['message'] ?? null)) {
            $out['event_type'] = 'message';
            $out = array_merge($out, $this->chat($message['chat'] ?? []), $this->from($message['from'] ?? []));
            $out['message_id'] = $message['message_id'] ?? '';

            if (! empty($message['text'])) {
                $out['text'] = (string) $message['text'];
            }

            $photos = $message['photo'] ?? null;
            if (is_array($photos) && $photos !== []) {
                // Telegram sends several sizes; the last one is the largest.
                $largest = end($photos);
                $out['has_photo'] = true;
                $out['photo_file_id'] = $largest['file_id'] ?? '';
                $out['photo_file_unique_id'] = $largest['file_unique_id'] ?? '';
                $out['photo_width'] = $largest['width'] ?? '';
                $out['photo_height'] = $largest['height'] ?? '';
                // The caption is the ticket description for screenshots.
                $out['text'] = (string) ($message['caption'] ?? '');
            }
        }

        if (is_array($callback = $update['callback_query'] ?? null)) {
            $out['event_type'] = 'callback';
            $out['callback_query_id'] = $callback['id'] ?? '';
            $out['callback_data'] = $callback['data'] ?? '';
            $out = array_merge($out, $this->from($callback['from'] ?? []), $this->chat($callback['message']['chat'] ?? []));
            $out['message_id'] = $callback['message']['message_id'] ?? '';
        }

        return $out;
    }

    private function chat(array $chat): array
    {
        return ['chat_id' => $chat['id'] ?? '', 'chat_type' => $chat['type'] ?? '', 'chat_title' => $chat['title'] ?? ''];
    }

    private function from(array $from): array
    {
        return [
            'user_id' => $from['id'] ?? '',
            'username' => $from['username'] ?? '',
            'first_name' => $from['first_name'] ?? '',
            'last_name' => $from['last_name'] ?? '',
        ];
    }
}
