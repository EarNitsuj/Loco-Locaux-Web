<?php

namespace App\Crawling\Location;

use App\Crawling\Data\RawEvent;
use App\Models\Commune;
use Illuminate\Support\Str;

class CommuneResolver
{
    public function resolve(RawEvent $raw): ?Commune
    {
        /*
        |--------------------------------------------------------------------------
        | Location evidence
        |--------------------------------------------------------------------------
        |
        | Different adapters may expose location information under slightly
        | different metadata keys.
        |
        | Address and venue remain the primary fields, but locality/city/
        | postcode-style fields are also useful deterministic evidence when
        | present.
        |
        */

        $candidates = array_filter(
            [
                $raw->metadata['address'] ?? null,
                $raw->metadata['venue'] ?? null,
                $raw->metadata['locality'] ?? null,
                $raw->metadata['city'] ?? null,
                $raw->metadata['postal_code'] ?? null,
                $raw->metadata['postcode'] ?? null,
            ],
            fn(mixed $value): bool =>
            is_string($value)
                && trim($value) !== ''
        );

        if ($candidates === []) {
            return null;
        }

        $haystack = $this->normalize(
            implode(' ', $candidates)
        );

        /*
        |--------------------------------------------------------------------------
        | Explicit Geneva locality aliases
        |--------------------------------------------------------------------------
        |
        | Some locality names appearing in postal addresses are not identical
        | to their official commune name.
        |
        | Example:
        |
        |   Théâtre du Loup
        |   Chemin de la Gravière 10
        |   1227 Les Acacias
        |
        | "Les Acacias" belongs to the Ville de Genève, but the address does not
        | necessarily contain the literal word "Genève".
        |
        | Keep these mappings deterministic. Do not infer unknown places.
        |
        */

        $aliases = [
            'aire-la-ville' => [
                'aire la ville',
            ],

            'anieres' => [
                'anieres',
                'chevrens',
            ],

            'avully' => [
                'avully',
                'gennecy',
                'epeisses',
            ],

            'avusy' => [
                'avusy',
                'athenaz',
                'sezegnin',
                'champlong',
                'eaumorte',
            ],

            'bardonnex' => [
                'bardonnex',
                'compesieres',
                'landecy',
                'charrot',
                'la croix de rozon',
                'croix de rozon',
            ],

            'bellevue' => [
                'bellevue',
            ],

            'bernex' => [
                'bernex',
                'lully',
                'sezenove',
                'loex',
            ],

            'carouge' => [
                'carouge',
                'vieux carouge',
            ],

            'cartigny' => [
                'cartigny',
                'moulin de vert',
                'la petite grave',
            ],

            'celigny' => [
                'celigny',
            ],

            'chancy' => [
                'chancy',
                'passeiry',
            ],

            'chene-bougeries' => [
                'chene bougeries',
                'conches',
                'grange canal',
                'gradelle',
            ],

            'chene-bourg' => [
                'chene bourg',
            ],

            'choulex' => [
                'choulex',
                'bonvard',
            ],

            'collex-bossy' => [
                'collex bossy',
                'collex',
                'bossy',
                'la rosiere',
                'la foretaille',
            ],

            'collonge-bellerive' => [
                'collonge bellerive',
                'collonge',
                'bellerive',
                'vesenaz',
                'saint maurice',
                'la pallanterie',
            ],

            'cologny' => [
                'cologny',
                'ruth',
            ],

            'confignon' => [
                'confignon',
            ],

            'corsier' => [
                'corsier',
            ],

            'dardagny' => [
                'dardagny',
                'la plaine',
                'malval',
                'essertines',
            ],

            'geneve' => [
                'geneve',

                // Four official sections of the Ville de Genève.
                'cite',
                'eaux vives',
                'petit saconnex',
                'plainpalais',

                // Common urban localities / neighbourhoods.
                'paquis',
                'saint gervais',
                'les grottes',
                'grottes',
                'saint jean',
                'champel',
                'jonction',
                'la jonction',
                'la cluse',
                'cluse',
                'les eaux vives',
                'florissant',
                'malagnou',
                'secheron',
                'servette',
                'charmilles',
                'les charmilles',
                'vieusseux',
                'nations',
                'rive',
                'bourg de four',
                'les bastions',
                'les delices',
                'cropettes',
                'montbrillant',
                'navigation',
                'rosaraie',
                'roseraie',
                'bout du monde',
                'minoteries',
                'coulouvreniere',
                'queue d arve',
                'les vernets',
                'frontenex',
                'vollandes',
                'villereuse',
                'montchoisy',
                'contamines',
                'ariana',
                'rigot',
                'varembé',
                'varembé',
                'grand pre',
                'grand pré',
                'moillebeau',
                'bouchet',
            ],

            'genthod' => [
                'genthod',
                'creux de genthod',
                'malagny',
            ],

            'grand-saconnex' => [
                'grand saconnex',
                'le grand saconnex',
            ],

            'gy' => [
                'gy',
            ],

            'hermance' => [
                'hermance',
            ],

            'jussy' => [
                'jussy',
                'jussy l eveque',
                'jussy leveque',
                'sionnet',
                'lullier',
                'monniaz',
                'petit lullier',
            ],

            'laconnex' => [
                'laconnex',
            ],

            'lancy' => [
                'lancy',
                'grand lancy',
                'petit lancy',
                'surville',
            ],

            'meinier' => [
                'meinier',
                'corsinge',
                'carre d amont',
                'carre d aval',
                'compois',
                'essert',
            ],

            'meyrin' => [
                'meyrin',
                'mategnin',
                'cointrin',
            ],

            'onex' => [
                'onex',
                'evaux',
            ],

            'perly-certoux' => [
                'perly certoux',
                'perly',
                'certoux',
            ],

            'plan-les-ouates' => [
                'plan les ouates',
                'arare',
                'la chapelle',
                'saconnex d arve',
                'saconnex d arve dessus',
                'saconnex d arve dessous',
            ],

            'pregny-chambesy' => [
                'pregny chambesy',
                'chambesy',
                'pregny',
                'tonkin',
            ],

            'presinge' => [
                'presinge',
                'cara',
                'la renfile',
                'la louviere',
            ],

            'puplinge' => [
                'puplinge',
                'dardelles',
                'pesay',
            ],

            'russin' => [
                'russin',
                'verbois',
            ],

            'satigny' => [
                'satigny',
                'bourdigny',
                'choully',
                'montfleury',
                'peissy',
                'peney',
                'peney dessus',
                'peney dessous',
            ],

            'soral' => [
                'soral',
            ],

            'thonex' => [
                'thonex',
                'moillesulaz',
                'villette',
            ],

            'troinex' => [
                'troinex',
                'troinex dessous',
                'troinex dessus',
            ],

            'vandoeuvres' => [
                'vandoeuvres',
                'bessinge',
                'crete',
                'chougny',
            ],

            'vernier' => [
                'vernier',
                'vernier village',
                'chatelaine',
                'les avanchets',
                'avanchets',
                'aire',
                'le lignon',
                'lignon',
                'balexert',
                'libellules',
                'les libellules',
            ],

            'versoix' => [
                'versoix',
                'sauverny',
                'ecogia',
                'richelien',
            ],

            'veyrier' => [
                'veyrier',
                'vessy',
                'sierne',
                'petit veyrier',
                'pinchat',
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Match the most specific alias
        |--------------------------------------------------------------------------
        |
        | Do not depend on array order.
        |
        | If more than one term occurs in an address, prefer the longest alias.
        |
        | For example:
        |
        |   "Le Grand-Saconnex, Genève"
        |
        | must resolve to Grand-Saconnex rather than Genève.
        |
        */

        $aliasMatches = [];

        foreach ($aliases as $slug => $names) {
            foreach ($names as $name) {
                $normalizedName = $this->normalize(
                    $name
                );

                if (
                    ! $this->containsTerm(
                        $haystack,
                        $normalizedName
                    )
                ) {
                    continue;
                }

                $aliasMatches[] = [
                    'slug' => $slug,
                    'term' => $normalizedName,
                    'length' => mb_strlen(
                        $normalizedName
                    ),
                ];
            }
        }

        if ($aliasMatches !== []) {
            usort(
                $aliasMatches,
                static fn(array $left, array $right): int =>
                $right['length'] <=> $left['length']
            );

            $slug = $aliasMatches[0]['slug'];

            $commune = Commune::query()
                ->where('slug', $slug)
                ->first();

            if ($commune !== null) {
                return $commune;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Match official commune names
        |--------------------------------------------------------------------------
        |
        | Longest official commune names are checked first to avoid a shorter
        | name accidentally winning over a more specific one.
        |
        */

        foreach (
            Commune::query()
                ->orderByRaw('CHAR_LENGTH(name) DESC')
                ->get()
            as $commune
        ) {
            $name = $this->normalize(
                $commune->name
            );

            if (
                $name !== ''
                && $this->containsTerm(
                    $haystack,
                    $name
                )
            ) {
                return $commune;
            }
        }

        return null;
    }

    private function normalize(
        string $value
    ): string {
        $value = Str::ascii(
            mb_strtolower($value)
        );

        $value = preg_replace(
            '/[^a-z0-9]+/',
            ' ',
            $value
        ) ?? $value;

        return trim(
            preg_replace(
                '/\s+/',
                ' ',
                $value
            ) ?? $value
        );
    }

    private function containsTerm(
        string $haystack,
        string $needle
    ): bool {
        if ($needle === '') {
            return false;
        }

        return preg_match(
            '/(?:^|\s)'
                . preg_quote($needle, '/')
                . '(?:$|\s)/',
            $haystack
        ) === 1;
    }
}
