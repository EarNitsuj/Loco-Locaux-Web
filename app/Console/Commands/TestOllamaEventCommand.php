<?php

namespace App\Console\Commands;

use App\AI\EventNormalizationSchema;
use App\Models\Event;
use App\Models\EventSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Throwable;

class TestOllamaEventCommand extends Command
{
    protected $signature = 'events:ollama-test
        {--event-id= : Test a specific Event ID.}
        {--model=qwen3.5:9b : Ollama model to use.}
        {--url=http://127.0.0.1:11434 : Ollama server URL.}';

    protected $description =
    'Normalize one crawled event with Ollama using EventNormalizationSchema without modifying the Event.';

    public function handle(): int
    {
        try {
            $event = $this->resolveEvent();

            $model = trim(
                (string) $this->option('model')
            );

            $ollamaUrl = rtrim(
                trim(
                    (string) $this->option('url')
                ),
                '/'
            );

            if ($model === '') {
                $this->error(
                    'The Ollama model cannot be empty.'
                );

                return self::FAILURE;
            }

            if (
                $event->raw_html === null
                || trim($event->raw_html) === ''
            ) {
                $this->error(
                    "Event #{$event->id} does not contain raw_html."
                );

                return self::FAILURE;
            }

            $this->newLine();

            $this->info(
                "Testing Event #{$event->id}"
            );

            $this->line(
                'Source URL: '
                    . ($event->event_url ?: 'N/A')
            );

            $this->line(
                "Model: {$model}"
            );

            $this->line(
                'Raw HTML size: '
                    . number_format(
                        strlen($event->raw_html)
                    )
                    . ' bytes'
            );

            $this->newLine();

            $this->warn(
                'Sending event evidence to Ollama...'
            );

            $startedAt = microtime(true);

            $response = Http::connectTimeout(10)
                ->timeout(1800)
                ->acceptJson()
                ->asJson()
                ->post(
                    "{$ollamaUrl}/api/chat",
                    [
                        'model' => $model,

                        'stream' => false,

                        /*
                         * This is the important part:
                         *
                         * Ollama receives the exact same JSON Schema used by
                         * the Loco Locaux normalization pipeline.
                         */
                        'format' =>
                        EventNormalizationSchema::get(),

                        'messages' => [
                            [
                                'role' =>
                                'system',

                                'content' =>
                                $this->systemPrompt(),
                            ],
                            [
                                'role' =>
                                'user',

                                'content' =>
                                $this->eventPrompt(
                                    $event
                                ),
                            ],
                        ],

                        'options' => [
                            'temperature' =>
                            0,
                        ],
                    ]
                );

            $wallSeconds =
                microtime(true)
                - $startedAt;

            if (! $response->successful()) {
                $this->error(
                    'Ollama request failed.'
                );

                $this->line(
                    'HTTP status: '
                        . $response->status()
                );

                $this->line(
                    $response->body()
                );

                return self::FAILURE;
            }

            $payload =
                $response->json();

            $content =
                data_get(
                    $payload,
                    'message.content'
                );

            if (
                ! is_string($content)
                || trim($content) === ''
            ) {
                throw new RuntimeException(
                    'Ollama returned an empty message.content.'
                );
            }

            /*
             * Decode once to ensure Ollama really returned valid JSON.
             */
            $normalized =
                json_decode(
                    $content,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

            if (! is_array($normalized)) {
                throw new RuntimeException(
                    'Ollama output is not a JSON object.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Output
            |--------------------------------------------------------------------------
            */

            $this->newLine();

            $this->info(
                'Normalized JSON'
            );

            $this->newLine();

            $this->line(
                json_encode(
                    $normalized,
                    JSON_PRETTY_PRINT
                        | JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                        | JSON_THROW_ON_ERROR
                )
            );

            /*
            |--------------------------------------------------------------------------
            | Timing
            |--------------------------------------------------------------------------
            |
            | Ollama duration values are nanoseconds.
            |
            */

            $totalSeconds =
                $this->nanosecondsToSeconds(
                    data_get(
                        $payload,
                        'total_duration'
                    )
                );

            $loadSeconds =
                $this->nanosecondsToSeconds(
                    data_get(
                        $payload,
                        'load_duration'
                    )
                );

            $promptEvalSeconds =
                $this->nanosecondsToSeconds(
                    data_get(
                        $payload,
                        'prompt_eval_duration'
                    )
                );

            $generationSeconds =
                $this->nanosecondsToSeconds(
                    data_get(
                        $payload,
                        'eval_duration'
                    )
                );

            $this->newLine();

            $this->table(
                [
                    'Metric',
                    'Value',
                ],
                [
                    [
                        'Wall-clock time',
                        $this->formatSeconds(
                            $wallSeconds
                        ),
                    ],
                    [
                        'Ollama total',
                        $this->formatSeconds(
                            $totalSeconds
                        ),
                    ],
                    [
                        'Model loading',
                        $this->formatSeconds(
                            $loadSeconds
                        ),
                    ],
                    [
                        'Prompt processing',
                        $this->formatSeconds(
                            $promptEvalSeconds
                        ),
                    ],
                    [
                        'Generation',
                        $this->formatSeconds(
                            $generationSeconds
                        ),
                    ],
                    [
                        'Prompt tokens',
                        data_get(
                            $payload,
                            'prompt_eval_count',
                            'N/A'
                        ),
                    ],
                    [
                        'Output tokens',
                        data_get(
                            $payload,
                            'eval_count',
                            'N/A'
                        ),
                    ],
                ]
            );

            $this->newLine();

            $this->comment(
                'The normalized result was NOT saved to the Event.'
            );

            return self::SUCCESS;
        } catch (JsonException $exception) {
            $this->error(
                'Ollama returned invalid JSON: '
                    . $exception->getMessage()
            );

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error(
                $exception->getMessage()
            );

            return self::FAILURE;
        }
    }

    private function resolveEvent(): Event
    {
        $eventId = $this->option(
            'event-id'
        );

        /*
         * Explicit event ID takes priority.
         */
        if (
            $eventId !== null
            && trim((string) $eventId) !== ''
        ) {
            return Event::query()
                ->findOrFail(
                    (int) $eventId
                );
        }

        /*
         * Otherwise use the latest crawled Geneva Tourism event.
         */
        $source = EventSource::query()
            ->where(
                'adapter',
                'geneve-tourism'
            )
            ->first();

        if ($source === null) {
            throw new RuntimeException(
                'No active Geneva Tourism EventSource was found.'
            );
        }

        $event = Event::query()
            ->where(
                'event_source_id',
                $source->id
            )
            ->whereNotNull(
                'raw_html'
            )
            ->latest(
                'id'
            )
            ->first();

        if ($event === null) {
            throw new RuntimeException(
                'No crawled Geneva Tourism Event with raw_html was found.'
            );
        }

        return $event;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are the event normalization engine for Loco Locaux, a Geneva event discovery application.

Convert the supplied source evidence into the exact JSON structure required by the provided JSON Schema.

CORE RULES

1. Preserve source truth.
Never invent, infer, guess, embellish or manufacture factual information that is not supported by the supplied source evidence.

2. Use null or an empty array when information is genuinely unavailable and the schema permits it.

3. Do not omit required properties.

4. Do not introduce properties that are not defined by the JSON Schema.

5. The event title must be the actual event title, not a website heading, breadcrumb, commune name, navigation label or page title.

6. Preserve useful human-readable information while removing website navigation, cookie text, menus, footer text and unrelated page chrome.

7. Dates and times:
- occurrences.starts_at and occurrences.ends_at must use ISO 8601 whenever a date/time can be determined.
- Use Europe/Zurich as the local timezone for Geneva events unless the source explicitly indicates otherwise.
- Do not convert an uncertain date into a precise date.
- Preserve multiple genuine occurrences.
- Do not create duplicate occurrences.

8. Recurrence:
Do not collapse genuinely separate dates into one fabricated date range.
Do not invent recurrence rules.

9. Categories:
Use only category keys permitted by the schema.
Set exactly one category as primary when at least one category is assigned.

10. Audiences:
Use only audience keys permitted by the schema.
The event.audience field should preserve useful original human-readable audience wording where available.

11. Pricing:
Do not claim an event is free unless the source explicitly establishes that it is free.

12. Registration:
Do not claim registration is required unless the evidence supports it.

13. Images:
Only select a hero image URL that actually appears in the supplied source evidence.
Prefer the image that most clearly represents the event itself.
Avoid logos, icons, decorative interface images, tracking pixels and unrelated site imagery.

14. Location:
Do not invent latitude, longitude, map URL or street address.
Return null when those values are unavailable.

15. Content:
Preserve useful descriptive information from the source.
Do not reproduce navigation, cookie notices, unrelated promotions or generic website boilerplate.

16. Links and documents:
Only include URLs supported by the source evidence.

17. Source URL:
event.source.event_url must exactly use the supplied SOURCE EVENT URL.

18. Output:
Return the JSON object only.
PROMPT;
    }

    private function eventPrompt(
        Event $event
    ): string {
        $eventUrl =
            $event->event_url
            ?: '';

        return <<<PROMPT
Normalize the following Geneva event.

SOURCE EVENT URL:
{$eventUrl}

SOURCE EVIDENCE:
{$event->raw_html}
PROMPT;
    }

    private function nanosecondsToSeconds(
        mixed $nanoseconds
    ): ?float {
        if (! is_numeric($nanoseconds)) {
            return null;
        }

        return (float) $nanoseconds
            / 1_000_000_000;
    }

    private function formatSeconds(
        ?float $seconds
    ): string {
        if ($seconds === null) {
            return 'N/A';
        }

        if ($seconds < 60) {
            return number_format(
                $seconds,
                2
            ) . 's';
        }

        $minutes = floor(
            $seconds / 60
        );

        $remainingSeconds =
            $seconds
            - ($minutes * 60);

        return sprintf(
            '%dm %.2fs',
            $minutes,
            $remainingSeconds
        );
    }
}
