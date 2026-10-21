<?php

namespace App\AI;

class EventNormalizationSchema
{
    public static function get(): array
    {
        return [
            'type' => 'object',

            'properties' => [

                'event' => [
                    'type' => 'object',

                    'properties' => [

                        'schema_version' => [
                            'type' => 'string',
                        ],

                        'language' => [
                            'type' => ['string', 'null'],
                        ],

                        'identity' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => [
                                    'type' => 'string',
                                ],
                                'subtitle' => [
                                    'type' => ['string', 'null'],
                                ],
                                'category' => [
                                    'type' => ['string', 'null'],
                                ],
                            ],
                            'required' => [
                                'title',
                                'subtitle',
                                'category',
                            ],
                            'additionalProperties' => false,
                        ],

                        'categories' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'key' => [
                                        'type' => 'string',
                                        'enum' => EventTaxonomy::CATEGORIES,
                                    ],
                                    'primary' => [
                                        'type' => 'boolean',
                                    ],
                                ],
                                'required' => [
                                    'key',
                                    'primary',
                                ],
                                'additionalProperties' => false,
                            ],
                        ],

                        'summary' => [
                            'type' => ['string', 'null'],
                        ],

                        'media' => [
                            'type' => 'object',
                            'properties' => [
                                'hero' => [
                                    'type' => ['object', 'null'],
                                    'properties' => [
                                        'url' => [
                                            'type' => ['string', 'null'],
                                        ],
                                        'alt' => [
                                            'type' => ['string', 'null'],
                                        ],
                                        'credit' => [
                                            'type' => ['string', 'null'],
                                        ],
                                    ],
                                    'required' => [
                                        'url',
                                        'alt',
                                        'credit',
                                    ],
                                    'additionalProperties' => false,
                                ],
                            ],
                            'required' => ['hero'],
                            'additionalProperties' => false,
                        ],

                        'location' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => [
                                    'type' => ['string', 'null'],
                                ],
                                'address' => [
                                    'type' => ['string', 'null'],
                                ],
                                'latitude' => [
                                    'type' => ['number', 'null'],
                                ],
                                'longitude' => [
                                    'type' => ['number', 'null'],
                                ],
                                'map_url' => [
                                    'type' => ['string', 'null'],
                                ],
                            ],
                            'required' => [
                                'name',
                                'address',
                                'latitude',
                                'longitude',
                                'map_url',
                            ],
                            'additionalProperties' => false,
                        ],

                        'pricing' => [
                            'type' => 'object',
                            'properties' => [
                                'is_free' => [
                                    'type' => ['boolean', 'null'],
                                ],
                                'summary' => [
                                    'type' => ['string', 'null'],
                                ],
                                'groups' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'label' => [
                                                'type' => ['string', 'null'],
                                            ],
                                            'items' => [
                                                'type' => 'array',
                                                'items' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'label' => [
                                                            'type' => 'string',
                                                        ],
                                                        'value' => [
                                                            'type' => 'string',
                                                        ],
                                                    ],
                                                    'required' => [
                                                        'label',
                                                        'value',
                                                    ],
                                                    'additionalProperties' => false,
                                                ],
                                            ],
                                        ],
                                        'required' => [
                                            'label',
                                            'items',
                                        ],
                                        'additionalProperties' => false,
                                    ],
                                ],
                            ],
                            'required' => [
                                'is_free',
                                'summary',
                                'groups',
                            ],
                            'additionalProperties' => false,
                        ],

                        'registration' => [
                            'type' => 'object',
                            'properties' => [
                                'required' => [
                                    'type' => ['boolean', 'null'],
                                ],
                                'text' => [
                                    'type' => ['string', 'null'],
                                ],
                                'url' => [
                                    'type' => ['string', 'null'],
                                ],
                            ],
                            'required' => [
                                'required',
                                'text',
                                'url',
                            ],
                            'additionalProperties' => false,
                        ],

                        /*
                         * Original human-readable audience wording
                         * preserved from the source.
                         */
                        'audience' => [
                            'type' => ['string', 'null'],
                        ],

                        /*
                         * Structured audience keys for filtering/searching.
                         */
                        'audiences' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                                'enum' => EventTaxonomy::AUDIENCES,
                            ],
                        ],

                        'organizers' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => [
                                        'type' => 'string',
                                    ],
                                    'url' => [
                                        'type' => ['string', 'null'],
                                    ],
                                ],
                                'required' => [
                                    'name',
                                    'url',
                                ],
                                'additionalProperties' => false,
                            ],
                        ],

                        'contacts' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'type' => [
                                        'type' => 'string',
                                        'enum' => [
                                            'email',
                                            'telephone',
                                            'mobile',
                                            'fax',
                                            'website',
                                            'other',
                                        ],
                                    ],
                                    'label' => [
                                        'type' => ['string', 'null'],
                                    ],
                                    'value' => [
                                        'type' => 'string',
                                    ],
                                ],
                                'required' => [
                                    'type',
                                    'label',
                                    'value',
                                ],
                                'additionalProperties' => false,
                            ],
                        ],

                        'accessibility' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'label' => [
                                        'type' => ['string', 'null'],
                                    ],
                                    'semantic_key' => [
                                        'type' => ['string', 'null'],
                                    ],
                                    'icon_url' => [
                                        'type' => ['string', 'null'],
                                    ],
                                    'details_url' => [
                                        'type' => ['string', 'null'],
                                    ],
                                ],
                                'required' => [
                                    'label',
                                    'semantic_key',
                                    'icon_url',
                                    'details_url',
                                ],
                                'additionalProperties' => false,
                            ],
                        ],

                        'highlights' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'label' => [
                                        'type' => 'string',
                                    ],
                                    'value' => [
                                        'type' => 'string',
                                    ],
                                ],
                                'required' => [
                                    'label',
                                    'value',
                                ],
                                'additionalProperties' => false,
                            ],
                        ],

                        'notices' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],

                        'content' => [
                            'type' => 'object',
                            'properties' => [
                                'sections' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'title' => [
                                                'type' => ['string', 'null'],
                                            ],
                                            'blocks' => [
                                                'type' => 'array',
                                                'items' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'type' => [
                                                            'type' => 'string',
                                                            'enum' => [
                                                                'paragraph',
                                                                'list',
                                                                'notice',
                                                            ],
                                                        ],
                                                        'text' => [
                                                            'type' => ['string', 'null'],
                                                        ],
                                                        'items' => [
                                                            'type' => 'array',
                                                            'items' => [
                                                                'type' => 'string',
                                                            ],
                                                        ],
                                                    ],
                                                    'required' => [
                                                        'type',
                                                        'text',
                                                        'items',
                                                    ],
                                                    'additionalProperties' => false,
                                                ],
                                            ],
                                        ],
                                        'required' => [
                                            'title',
                                            'blocks',
                                        ],
                                        'additionalProperties' => false,
                                    ],
                                ],
                            ],
                            'required' => ['sections'],
                            'additionalProperties' => false,
                        ],

                        'documents' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'label' => [
                                        'type' => 'string',
                                    ],
                                    'url' => [
                                        'type' => 'string',
                                    ],
                                ],
                                'required' => [
                                    'label',
                                    'url',
                                ],
                                'additionalProperties' => false,
                            ],
                        ],

                        'links' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'label' => [
                                        'type' => 'string',
                                    ],
                                    'url' => [
                                        'type' => 'string',
                                    ],
                                ],
                                'required' => [
                                    'label',
                                    'url',
                                ],
                                'additionalProperties' => false,
                            ],
                        ],

                        'source' => [
                            'type' => 'object',
                            'properties' => [
                                'event_url' => [
                                    'type' => 'string',
                                ],
                            ],
                            'required' => ['event_url'],
                            'additionalProperties' => false,
                        ],
                    ],

                    'required' => [
                        'schema_version',
                        'language',
                        'identity',
                        'categories',
                        'summary',
                        'media',
                        'location',
                        'pricing',
                        'registration',
                        'audience',
                        'audiences',
                        'organizers',
                        'contacts',
                        'accessibility',
                        'highlights',
                        'notices',
                        'content',
                        'documents',
                        'links',
                        'source',
                    ],

                    'additionalProperties' => false,
                ],

                'occurrences' => [
                    'type' => 'array',

                    'items' => [
                        'type' => 'object',

                        'properties' => [
                            'starts_at' => [
                                'type' => ['string', 'null'],
                            ],
                            'ends_at' => [
                                'type' => ['string', 'null'],
                            ],
                        ],

                        'required' => [
                            'starts_at',
                            'ends_at',
                        ],

                        'additionalProperties' => false,
                    ],
                ],
            ],

            'required' => [
                'event',
                'occurrences',
            ],

            'additionalProperties' => false,
        ];
    }
}
