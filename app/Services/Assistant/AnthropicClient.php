<?php

namespace App\Services\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Claude Messages API, called from the server only (the key stays in .env, never in the app).
 * Uses structured outputs so the answer is JSON that matches a schema.
 *
 * Raw HTTP through Laravel's client keeps the project free of a new Composer dependency; swapping in the
 * official anthropic-ai/sdk later only changes this class.
 */
class AnthropicClient
{
    private const API_VERSION = '2026-10-21';

    /**
     * @param  string|list<array<string, mixed>>  $userContent  text, or content blocks (for example an image and text)
     * @param  array<string, mixed>  $schema  JSON schema of the answer
     * @return array{data: array<string, mixed>, input_tokens: int, output_tokens: int}
     *
     * @throws RuntimeException when the API cannot answer
     */
    public function structured(string $system, string|array $userContent, array $schema): array
    {
        $key = config('wibuy.assistant.api_key');

        if (! $key) {
            throw new RuntimeException('ANTHROPIC_API_KEY is not set.');
        }

        try {
            $response = Http::baseUrl(config('wibuy.assistant.base_url'))
                ->withHeaders([
                    'x-api-key' => $key,
                    'anthropic-version' => self::API_VERSION,
                    // If a safety classifier declines, the API re-runs the request on a fallback model.
                    'anthropic-beta' => 'server-side-fallback-2026-07-01',
                ])
                ->timeout(60)->retry(2, 500, throw: false)
                ->post('/v1/messages', [
                    'model' => config('wibuy.assistant.model'),
                    'max_tokens' => 4000,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $userContent]],
                    // Extraction is a simple task: low effort keeps it fast and cheap.
                    'output_config' => [
                        'effort' => 'low',
                        'format' => ['type' => 'json_schema', 'schema' => $schema],
                    ],
                    'fallbacks' => 'default',
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach the AI service.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException('The AI service answered '.$response->status().'.');
        }

        $body = $response->json();

        if (($body['stop_reason'] ?? null) === 'refusal') {
            throw new RuntimeException('The AI service declined this request.');
        }

        $text = collect($body['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');
        $data = json_decode($text, true);

        if (! is_array($data)) {
            throw new RuntimeException('The AI answer was not readable.');
        }

        return [
            'data' => $data,
            'input_tokens' => (int) ($body['usage']['input_tokens'] ?? 0),
            'output_tokens' => (int) ($body['usage']['output_tokens'] ?? 0),
        ];
    }
}
