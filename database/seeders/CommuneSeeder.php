<?php

namespace Database\Seeders;

use App\Models\Commune;
use Illuminate\Database\Seeder;

class CommuneSeeder extends Seeder
{
    public function run(): void
    {
        $communes = [
            ['Aire-la-Ville', 'aire-la-ville', 'https://www.aire-la-ville.ch'],
            ['Anières', 'anieres', 'https://www.anieres.ch'],
            ['Avully', 'avully', 'https://www.avully.ch'],
            ['Avusy', 'avusy', 'https://www.avusy.ch'],
            ['Bardonnex', 'bardonnex', 'https://www.bardonnex.ch'],
            ['Bellevue', 'bellevue', 'https://www.mairie-bellevue.ch'],
            ['Bernex', 'bernex', 'https://www.bernex.ch'],
            ['Carouge', 'carouge', 'https://www.carouge.ch'],
            ['Cartigny', 'cartigny', 'https://www.cartigny.ch'],
            ['Céligny', 'celigny', 'https://www.celigny.ch'],
            ['Chancy', 'chancy', 'https://www.chancy.ch'],
            ['Chêne-Bougeries', 'chene-bougeries', 'https://www.chene-bougeries.ch'],
            ['Chêne-Bourg', 'chene-bourg', 'https://www.chene-bourg.ch'],
            ['Choulex', 'choulex', 'https://www.choulex.ch'],
            ['Collex-Bossy', 'collex-bossy', 'https://www.collex-bossy.ch'],
            ['Collonge-Bellerive', 'collonge-bellerive', 'https://www.collonge-bellerive.ch'],
            ['Cologny', 'cologny', 'https://www.cologny.ch'],
            ['Confignon', 'confignon', 'https://www.confignon.ch'],
            ['Corsier', 'corsier', 'https://www.corsier.ch'],
            ['Dardagny', 'dardagny', 'https://www.dardagny.ch'],
            ['Genève', 'geneve', 'https://www.geneve.ch'],
            ['Genthod', 'genthod', 'https://www.genthod.ch'],
            ['Grand-Saconnex', 'grand-saconnex', 'https://www.grand-saconnex.ch'],
            ['Gy', 'gy', 'https://www.gy.ch'],
            ['Hermance', 'hermance', 'https://www.hermance.ch'],
            ['Jussy', 'jussy', 'https://www.jussy.ch'],
            ['Laconnex', 'laconnex', 'https://www.laconnex.ch'],
            ['Lancy', 'lancy', 'https://www.lancy.ch'],
            ['Meinier', 'meinier', 'https://www.meinier.ch'],
            ['Meyrin', 'meyrin', 'https://www.meyrin.ch'],
            ['Onex', 'onex', 'https://www.onex.ch'],
            ['Perly-Certoux', 'perly-certoux', 'https://www.perly-certoux.ch'],
            ['Plan-les-Ouates', 'plan-les-ouates', 'https://www.plan-les-ouates.ch'],
            ['Pregny-Chambésy', 'pregny-chambesy', 'https://www.pregny-chambesy.ch'],
            ['Presinge', 'presinge', 'https://www.presinge.ch'],
            ['Puplinge', 'puplinge', 'https://www.puplinge.ch'],
            ['Russin', 'russin', 'https://www.russin.ch'],
            ['Satigny', 'satigny', 'https://www.satigny.ch'],
            ['Soral', 'soral', 'https://www.soral.ch'],
            ['Thônex', 'thonex', 'https://www.thonex.ch'],
            ['Troinex', 'troinex', 'https://www.troinex.ch'],
            ['Vandoeuvres', 'vandoeuvres', 'https://www.vandoeuvres.ch'],
            ['Vernier', 'vernier', 'https://www.vernier.ch'],
            ['Versoix', 'versoix', 'https://www.versoix.ch'],
            ['Veyrier', 'veyrier', 'https://www.veyrier.ch'],
        ];

        foreach ($communes as [$name, $slug, $url]) {
            Commune::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'population' => null, 'official_website_url' => $url],
            );
        }
    }
}
