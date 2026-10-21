<?php

namespace App\Content\Data;

final readonly class CanonicalDescription
{
    public function __construct(
        public ?string $html,
        public ?string $text,
        public bool $changed,
    ) {}
}
