<?php

namespace App\Crawling\Documents;

use App\Crawling\Exceptions\CrawlerRequestException;
use App\Crawling\Fetchers\HttpPageFetcher;
use Symfony\Component\Process\Process;

class PdfEvidenceExtractor
{
    public function __construct(
        private readonly HttpPageFetcher $fetcher,
    ) {}

    /**
     * @param  list<string>  $allowedHosts
     *
     * @return array{
     *     url:string,
     *     requested_url:string,
     *     text:string,
     *     sha256:string,
     *     content_type:?string
     * }
     */
    public function extract(
        string $url,
        array $allowedHosts = []
    ): array {
        $document = $this->fetcher->getDocument(
            $url,
            $allowedHosts
        );

        $tempFile = tempnam(
            sys_get_temp_dir(),
            'crawler-pdf-'
        );

        if ($tempFile === false) {
            throw new CrawlerRequestException(
                "Unable to create temporary PDF file for {$url}"
            );
        }

        if (
            file_put_contents(
                $tempFile,
                $document->body
            ) === false
        ) {
            @unlink($tempFile);

            throw new CrawlerRequestException(
                "Unable to write temporary PDF file for {$url}"
            );
        }

        try {
            /*
            |--------------------------------------------------------------------------
            | Poppler / pdftotext
            |--------------------------------------------------------------------------
            |
            | -layout:
            | Preserve enough of the visual line structure to make flyers and
            | programmes easier for the downstream AI to interpret.
            |
            | -:
            | Send extracted text to stdout instead of another temporary file.
            |
            */

            $process = new Process([
                'pdftotext',
                '-layout',
                '-enc',
                'UTF-8',
                $tempFile,
                '-',
            ]);

            $process->setTimeout(30);

            $process->run();

            if (! $process->isSuccessful()) {
                throw new CrawlerRequestException(
                    'PDF text extraction failed for '
                        . $url
                        . ': '
                        . trim(
                            $process->getErrorOutput()
                        )
                );
            }

            $text = $this->cleanText(
                $process->getOutput()
            );

            /*
            |--------------------------------------------------------------------------
            | Image-only/scanned PDF
            |--------------------------------------------------------------------------
            |
            | We do NOT perform OCR here.
            |
            | The caller can catch this failure while still preserving the PDF
            | link as evidence.
            |
            */

            if ($text === '') {
                throw new CrawlerRequestException(
                    "PDF contains no extractable text for {$url}"
                );
            }

            return [
                'url' =>
                $document->finalUrl,

                'requested_url' =>
                $document->requestedUrl,

                'text' =>
                $text,

                'sha256' =>
                hash(
                    'sha256',
                    $document->body
                ),

                'content_type' =>
                $document->contentType,
            ];
        } finally {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    private function cleanText(
        string $text
    ): string {
        /*
        | Normalize line endings.
        */

        $text = str_replace(
            ["\r\n", "\r"],
            "\n",
            $text
        );

        /*
        | Remove null bytes occasionally emitted from malformed PDFs.
        */

        $text = str_replace(
            "\0",
            '',
            $text
        );

        /*
        | pdftotext represents PDF page boundaries using form-feed.
        */

        $text = str_replace(
            "\f",
            "\n\n",
            $text
        );

        /*
        | Preserve meaningful line structure while removing trailing spaces.
        */

        $lines = preg_split(
            '/\n/u',
            $text
        ) ?: [];

        $lines = array_map(
            static fn(string $line): string =>
            rtrim($line),
            $lines
        );

        /*
        | Avoid huge runs of blank lines produced by designed flyers.
        */

        $text = implode(
            "\n",
            $lines
        );

        $text = preg_replace(
            '/\n{4,}/u',
            "\n\n\n",
            $text
        ) ?: $text;

        return trim($text);
    }
}
