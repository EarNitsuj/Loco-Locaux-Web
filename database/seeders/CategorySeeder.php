<?php

namespace Database\Seeders;

use App\AI\EventTaxonomy;
use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $translations = [
            'arts_culture' => [
                'name_en' => 'Arts & Culture',
                'name_fr' => 'Arts & culture',
                'name_it' => 'Arte e cultura',
                'name_de' => 'Kunst & Kultur',
                'name_es' => 'Arte y cultura',
                'name_pt' => 'Arte e cultura',
            ],

            'music' => [
                'name_en' => 'Music',
                'name_fr' => 'Musique',
                'name_it' => 'Musica',
                'name_de' => 'Musik',
                'name_es' => 'Música',
                'name_pt' => 'Música',
            ],

            'theatre_performance' => [
                'name_en' => 'Theatre & Performance',
                'name_fr' => 'Théâtre & spectacle',
                'name_it' => 'Teatro & spettacolo',
                'name_de' => 'Theater & Performance',
                'name_es' => 'Teatro & espectáculo',
                'name_pt' => 'Teatro & espetáculo',
            ],

            'cinema' => [
                'name_en' => 'Cinema',
                'name_fr' => 'Cinéma',
                'name_it' => 'Cinema',
                'name_de' => 'Kino',
                'name_es' => 'Cine',
                'name_pt' => 'Cinema',
            ],

            'dance' => [
                'name_en' => 'Dance',
                'name_fr' => 'Danse',
                'name_it' => 'Danza',
                'name_de' => 'Tanz',
                'name_es' => 'Danza',
                'name_pt' => 'Dança',
            ],

            'exhibitions' => [
                'name_en' => 'Exhibitions',
                'name_fr' => 'Expositions',
                'name_it' => 'Mostre',
                'name_de' => 'Ausstellungen',
                'name_es' => 'Exposiciones',
                'name_pt' => 'Exposições',
            ],

            'festivals_celebrations' => [
                'name_en' => 'Festivals & Celebrations',
                'name_fr' => 'Festivals & célébrations',
                'name_it' => 'Festival & celebrazioni',
                'name_de' => 'Festivals & Feiern',
                'name_es' => 'Festivales & celebraciones',
                'name_pt' => 'Festivais & celebrações',
            ],

            'literature' => [
                'name_en' => 'Literature',
                'name_fr' => 'Littérature',
                'name_it' => 'Letteratura',
                'name_de' => 'Literatur',
                'name_es' => 'Literatura',
                'name_pt' => 'Literatura',
            ],

            'conferences_talks' => [
                'name_en' => 'Conferences & Talks',
                'name_fr' => 'Conférences & rencontres',
                'name_it' => 'Conferenze & incontri',
                'name_de' => 'Konferenzen & Vorträge',
                'name_es' => 'Conferencias & charlas',
                'name_pt' => 'Conferências & palestras',
            ],

            'education_workshops' => [
                'name_en' => 'Education & Workshops',
                'name_fr' => 'Éducation & ateliers',
                'name_it' => 'Educazione & laboratori',
                'name_de' => 'Bildung & Workshops',
                'name_es' => 'Educación & talleres',
                'name_pt' => 'Educação & oficinas',
            ],

            'family_children' => [
                'name_en' => 'Family & Children',
                'name_fr' => 'Famille & enfants',
                'name_it' => 'Famiglia & bambini',
                'name_de' => 'Familie & Kinder',
                'name_es' => 'Familia & niños',
                'name_pt' => 'Família & crianças',
            ],

            'sports_movement' => [
                'name_en' => 'Sports & Movement',
                'name_fr' => 'Sports & mouvement',
                'name_it' => 'Sport & movimento',
                'name_de' => 'Sport & Bewegung',
                'name_es' => 'Deportes & movimiento',
                'name_pt' => 'Desporto & movimento',
            ],

            'health_wellbeing' => [
                'name_en' => 'Health & Well-being',
                'name_fr' => 'Santé & bien-être',
                'name_it' => 'Salute & benessere',
                'name_de' => 'Gesundheit & Wohlbefinden',
                'name_es' => 'Salud & bienestar',
                'name_pt' => 'Saúde & bem-estar',
            ],

            'nature_environment' => [
                'name_en' => 'Nature & Environment',
                'name_fr' => 'Nature & environnement',
                'name_it' => 'Natura & ambiente',
                'name_de' => 'Natur & Umwelt',
                'name_es' => 'Naturaleza & medio ambiente',
                'name_pt' => 'Natureza & ambiente',
            ],

            'food_markets' => [
                'name_en' => 'Food & Markets',
                'name_fr' => 'Gastronomie & marchés',
                'name_it' => 'Gastronomia & mercati',
                'name_de' => 'Essen & Märkte',
                'name_es' => 'Gastronomía & mercados',
                'name_pt' => 'Gastronomia & mercados',
            ],

            'community_social' => [
                'name_en' => 'Community & Social Life',
                'name_fr' => 'Vie communautaire & sociale',
                'name_it' => 'Comunità & vita sociale',
                'name_de' => 'Gemeinschaft & soziales Leben',
                'name_es' => 'Comunidad & vida social',
                'name_pt' => 'Comunidade & vida social',
            ],

            'public_civic' => [
                'name_en' => 'Public & Civic Life',
                'name_fr' => 'Vie publique & citoyenne',
                'name_it' => 'Vita pubblica & civica',
                'name_de' => 'Öffentliches & bürgerschaftliches Leben',
                'name_es' => 'Vida pública & cívica',
                'name_pt' => 'Vida pública & cívica',
            ],

            'heritage_history' => [
                'name_en' => 'Heritage & History',
                'name_fr' => 'Patrimoine & histoire',
                'name_it' => 'Patrimonio & storia',
                'name_de' => 'Kulturerbe & Geschichte',
                'name_es' => 'Patrimonio & historia',
                'name_pt' => 'Património & história',
            ],

            'games_leisure' => [
                'name_en' => 'Games & Leisure',
                'name_fr' => 'Jeux & loisirs',
                'name_it' => 'Giochi & tempo libero',
                'name_de' => 'Spiele & Freizeit',
                'name_es' => 'Juegos & ocio',
                'name_pt' => 'Jogos & lazer',
            ],

            'technology_innovation' => [
                'name_en' => 'Technology & Innovation',
                'name_fr' => 'Technologie & innovation',
                'name_it' => 'Tecnologia & innovazione',
                'name_de' => 'Technologie & Innovation',
                'name_es' => 'Tecnología & innovación',
                'name_pt' => 'Tecnologia & inovação',
            ],

            'nightlife' => [
                'name_en' => 'Nightlife',
                'name_fr' => 'Vie nocturne',
                'name_it' => 'Vita notturna',
                'name_de' => 'Nachtleben',
                'name_es' => 'Vida nocturna',
                'name_pt' => 'Vida noturna',
            ],
        ];

        foreach (EventTaxonomy::CATEGORIES as $key) {
            Category::updateOrCreate(
                ['key' => $key],
                $translations[$key],
            );
        }
    }
}
