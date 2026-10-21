<?php

namespace App\Services;

use App\AI\EventNormalizationPrompt;
use App\AI\EventNormalizationSchema;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIEventNormalizer
{
    public function normalize(
        string $rawHtml,
        string $eventUrl,
        ?string $imageUrl = null,
    ): array {
        $response = Http::withToken(config('services.openai.key'))
            ->acceptJson()
            ->timeout(180)
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('services.openai.event_model'),

                'reasoning' => [
                    'effort' => 'low',
                ],

                'input' => [
                    [
                        'role' => 'system',
                        'content' => EventNormalizationPrompt::instructions(),
                    ],

                    [
                        'role' => 'user',
                        'content' => implode("\n\n", [
                            'SOURCE EVENT URL:',
                            $eventUrl,

                            'SOURCE IMAGE URL:',
                            $imageUrl ?? 'Not separately supplied.',

                            'RAW EVENT HTML:',
                            $rawHtml,
                        ]),
                    ],
                ],

                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'loco_locaux_event',
                        'strict' => true,
                        'schema' => EventNormalizationSchema::get(),
                    ],
                ],
            ]);

        $response->throw();

        $body = $response->json();

        if (($body['status'] ?? null) !== 'completed') {
            throw new RuntimeException(
                'OpenAI response did not complete: ' .
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

        if (!$outputText) {
            throw new RuntimeException('OpenAI returned no structured output.');
        }

        $normalized = json_decode(
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

        $pricing = config('services.openai.event_pricing');

        $costUsd =
            ($uncachedInputTokens / 1_000_000 * $pricing['input'])
            + ($cachedInputTokens / 1_000_000 * $pricing['cached_input'])
            + ($outputTokens / 1_000_000 * $pricing['output']);

        return [
            'normalized' => $normalized,

            'usage' => [
                'model' => config('services.openai.event_model'),
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cached_input_tokens' => $cachedInputTokens,
                'cost_usd' => round($costUsd, 6),
            ],
        ];
    }
}
