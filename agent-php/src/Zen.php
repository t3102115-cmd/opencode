<?php

declare(strict_types=1);

/**
 * Minimal client for the OpenAI-compatible chat/completions endpoint of
 * OpenCode Zen (https://opencode.ai/zen). Used with the free-tier models,
 * which all speak this same tool-calling format.
 */
final class Zen
{
    public static function chat(string $model, array $messages, array $tools): array
    {
        $apiKey = (string) Config::get('zen_api_key');
        if ($apiKey === '') {
            throw new RuntimeException('No OpenCode Zen API key configured. Add one in Settings.');
        }

        $baseUrl = (string) Config::get('zen_base_url');
        $body = [
            'model' => $model,
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ];

        $ch = curl_init(rtrim($baseUrl, '/') . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Zen request failed: ' . $curlError);
        }

        $decoded = json_decode((string) $response, true);
        if ($status >= 400 || !is_array($decoded)) {
            throw new RuntimeException("Zen API error (HTTP $status): " . substr((string) $response, 0, 2000));
        }

        $message = $decoded['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new RuntimeException('Zen API returned an unexpected response shape.');
        }

        return [
            'message' => $message,
            'usage' => $decoded['usage'] ?? [],
        ];
    }
}
