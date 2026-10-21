<?php

namespace App\Console\Commands;

use App\Services\OpenAIEventNormalizer;
use Illuminate\Console\Command;

class TestEventNormalizer extends Command
{
    protected $signature = 'events:test-normalizer
        {file : Path to raw HTML fixture}
        {--url= : Original event URL}
        {--image= : Original event image URL}';

    protected $description = 'Test OpenAI event normalization against raw HTML';

    public function handle(OpenAIEventNormalizer $normalizer): int
    {
        $path = $this->argument('file');

        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $rawHtml = file_get_contents($path);

        $result = $normalizer->normalize(
            rawHtml: $rawHtml,
            eventUrl: $this->option('url') ?? '',
            imageUrl: $this->option('image'),
        );

        $this->line(
            json_encode(
                $result,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
            )
        );

        return self::SUCCESS;
    }
}
