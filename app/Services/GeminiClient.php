<?php

namespace App\Services;

use GuzzleHttp\Client;

class GeminiClient
{
    private Client $http;
    private string $endpoint;
    private string $model;
    private ?string $apiKey;
    private float $temperature;

    public function __construct()
    {
        $this->endpoint    = config('ai.endpoint');
        $this->model       = config('ai.model');
        $this->apiKey      = config('ai.api_key');
        $this->temperature = (float) config('ai.temperature', 0.2);
        $this->http = new Client(['timeout' => 20]);
    }

    public function generateText(string $prompt): string
    {
        $url = "{$this->endpoint}/models/{$this->model}:generateContent?key={$this->apiKey}";

        $res = $this->http->post($url, [
            'json' => [
                'generationConfig' => [
                    'temperature' => $this->temperature,
                ],
                'contents' => [[
                    'role'  => 'user',
                    'parts' => [['text' => $prompt]],
                ]],
            ],
        ]);

        $data = json_decode((string) $res->getBody(), true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return trim($text);
    }

    public function generateJson(string $prompt): array
    {
        // Force JSON-only
        $text = $this->generateText("Return ONLY valid minified JSON. No prose. \n\n".$prompt);

        // Try to extract/parse JSON even if model adds code fences
        $json = $this->extractJson($text);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : ['_raw' => $text];
    }

    public function chat(array $messages, ?string $systemPrompt = null): string
    {
        $url = "{$this->endpoint}/models/{$this->model}:generateContent?key={$this->apiKey}";

        $contents = [];
        if ($systemPrompt) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => $systemPrompt]],
            ];
        }

        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role = strtolower((string)($msg['role'] ?? 'user'));
            $content = trim((string)($msg['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $role = $role === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $content]],
            ];
        }

        if (empty($contents)) {
            return '';
        }

        $res = $this->http->post($url, [
            'json' => [
                'generationConfig' => [
                    'temperature' => $this->temperature,
                ],
                'contents' => $contents,
            ],
        ]);

        $data = json_decode((string) $res->getBody(), true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return trim($text);
    }

    private function extractJson(string $s): string
    {
        if (preg_match('/\{.*\}/s', $s, $m)) return $m[0];
        if (preg_match('/\[\s*{.*}\s*]/s', $s, $m)) return $m[0];
        return $s;
    }
}
