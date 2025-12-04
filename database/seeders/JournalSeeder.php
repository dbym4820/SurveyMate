<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Journal;

class JournalSeeder extends Seeder
{
    public function run(): void
    {
        $journals = [
            [
                'id' => 'bjet',
                'name' => 'BJET',
                'full_name' => 'British Journal of Educational Technology',
                'publisher' => 'Wiley',
                'rss_url' => 'https://onlinelibrary.wiley.com/action/showFeed?jc=14678535&type=etoc&feed=rss',
                'category' => 'EdTech',
                'color' => 'bg-red-500',
            ],
            [
                'id' => 'ijaied',
                'name' => 'IJAIED',
                'full_name' => 'International Journal of Artificial Intelligence in Education',
                'publisher' => 'Springer',
                'rss_url' => 'https://link.springer.com/search.rss?facet-content-type=Article&facet-journal-id=40593',
                'category' => 'AIED',
                'color' => 'bg-blue-500',
            ],
            [
                'id' => 'tics',
                'name' => 'TICS',
                'full_name' => 'Trends in Cognitive Sciences',
                'publisher' => 'Elsevier',
                'rss_url' => 'https://rss.sciencedirect.com/publication/science/13646613',
                'category' => 'Cognitive Science',
                'color' => 'bg-green-500',
            ],
        ];

        foreach ($journals as $journal) {
            Journal::updateOrCreate(
                ['id' => $journal['id']],
                $journal
            );
        }
    }
}
