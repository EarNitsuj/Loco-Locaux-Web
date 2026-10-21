<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\OpenAIEventTranslator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TranslateEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public int $eventId,
    ) {}

    public function handle(
        OpenAIEventTranslator $translator,
    ): void {
        $event = Event::findOrFail($this->eventId);

        $normalizedJson = $event->normalized_json;

        if (! $normalizedJson) {
            return;
        }

        $sourceLocale = $normalizedJson['event']['language'] ?? null;

        $targetLocales = [
            'en',
            'fr',
            'de',
            'it',
            'es',
            'pt',
        ];

        foreach ($targetLocales as $locale) {
            if ($locale === $sourceLocale) {
                continue;
            }

            $translation = $translator->translate(
                normalizedJson: $normalizedJson,
                targetLocale: $locale,
            );

            $event->translations()->updateOrCreate(
                [
                    'locale' => $locale,
                ],
                [
                    'normalized_json' => $translation['translated'],
                    'translation_cost_usd' => $translation['usage']['cost_usd'],
                    'translation_input_tokens' => $translation['usage']['input_tokens'],
                    'translation_output_tokens' => $translation['usage']['output_tokens'],
                    'translation_model' => $translation['usage']['model'],
                ],
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        report($exception);
    }
}
