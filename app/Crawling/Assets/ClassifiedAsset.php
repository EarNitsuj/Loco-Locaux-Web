<?php

namespace App\Crawling\Assets;

final readonly class ClassifiedAsset
{
    public function __construct(
        public string $url,
        public AssetRole $role,
        public ?string $semanticKey = null,
        public ?string $label = null,
        public ?string $reason = null,
    ) {}
}
