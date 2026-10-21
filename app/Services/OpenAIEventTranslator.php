<?php

namespace App\Services;

use App\AI\EventNormalizationSchema;
use App\AI\EventTranslationPrompt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIEventTranslator
{
    public function translate(
        array $normalizedJson,
        string $targetLocale,
    ): array {
        $response = Http::withToken(config('services.openai.key'))
            ->acceptJson()
            ->timeout(180)
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('services.openai.event_translation_model'),

                'reasoning' => [
                    'effort' => 'low',
                ],

                'input' => [
                    [
                        'role' => 'system',
                        'content' => EventTranslationPrompt::instructions(
                            $targetLocale
                        ),
                    ],

                    [
                        'role' => 'user',
                        'content' => json_encode(
                            $normalizedJson,
                            JSON_UNESCAPED_UNICODE
                                | JSON_UNESCAPED_SLASHES
                                | JSON_THROW_ON_ERROR,
                        ),
                    ],
                ],

                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'loco_locaux_event_translation',
                        'strict' => true,
                        'schema' => EventNormalizationSchema::get(),
                    ],
                ],
            ]);

        $response->throw();

        $body = $response->json();

        if (($body['status'] ?? null) !== 'completed') {
            throw new RuntimeException(
                'OpenAI translation response did not complete: ' .
                    json_encode($body['incomplete_details'] ?? null)
            );
        }

        $outputText = null;

        foreach ($body['output'] ?? [] as $output) {
            if (($output['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ($output['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'output_text') {
                    $outputText = $content['text'] ?? null;

                    break 2;
                }
            }
        }

        if (! $outputText) {
            throw new RuntimeException(
                'OpenAI returned no translated structured output.'
            );
        }

        $translated = json_decode(
            $outputText,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $usage = $body['usage'] ?? [];

        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        $cachedInputTokens = (int) (
            $usage['input_tokens_details']['cached_tokens'] ?? 0
        );

        $uncachedInputTokens = max(
            0,
            $inputTokens - $cachedInputTokens
        );

        $pricing = config('services.openai.event_translation_pricing');

        $costUsd =
            ($uncachedInputTokens / 1_000_000 * $pricing['input'])
            + ($cachedInputTokens / 1_000_000 * $pricing['cached_input'])
            + ($outputTokens / 1_000_000 * $pricing['output']);

        return [
            'translated' => $translated,

            'usage' => [
                'model' => config('services.openai.event_translation_model'),
                'input_tokens' => $inputTokens,
                'cached_input_tokens' => $cachedInputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd' => round($costUsd, 6),
            ],
        ];
    }
}
