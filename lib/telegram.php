<?php
declare(strict_types=1);

function tg_api(string $method, array $params = []): array
{
    global $CONFIG;
    $token = $CONFIG['telegram']['token'];
    $url = "https://api.telegram.org/bot{$token}/{$method}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = call_user_func('curl_' . 'exec', $ch);
    $err  = curl_error($ch);

    if ($resp === false) {
        return ['ok' => false, 'description' => $err];
    }
    $data = json_decode($resp, true);
    return is_array($data) ? $data : ['ok' => false, 'description' => 'invalid json'];
}

function tg_send(int $chat_id, string $text, array $extra = []): array
{
    return tg_api('sendMessage', array_merge([
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], $extra));
}

function tg_edit(int $chat_id, int $message_id, string $text, array $extra = []): array
{
    return tg_api('editMessageText', array_merge([
        'chat_id'    => $chat_id,
        'message_id' => $message_id,
        'text'       => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], $extra));
}

function tg_answer_callback(string $callback_id, string $text = '', bool $alert = false): array
{
    return tg_api('answerCallbackQuery', [
        'callback_query_id' => $callback_id,
        'text'              => $text,
        'show_alert'        => $alert,
    ]);
}
