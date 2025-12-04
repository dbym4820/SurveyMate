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
                'id' => 'ijaied',
                'name' => 'IJAIED',
                'full_name' => 'International Journal of Artificial Intelligence in Education',
                'publisher' => 'Springer',
                'rss_url' => 'https://link.springer.com/search.rss?facet-content-type=Article&facet-journal-id=40593',
                'category' => 'AIED',
                'color' => 'bg-blue-500',
            ],
            [
                'id' => 'metacognition',
                'name' => 'Metacognition & Learning',
                'full_name' => 'Metacognition and Learning',
                'publisher' => 'Springer',
                'rss_url' => 'https://link.springer.com/search.rss?facet-content-type=Article&facet-journal-id=11409',
                'category' => 'Learning Sciences',
                'color' => 'bg-purple-500',
            ],
            [
                'id' => 'cogsci',
                'name' => 'Cognitive Science',
                'full_name' => 'Cognitive Science',
                'publisher' => 'Wiley',
                'rss_url' => 'https://onlinelibrary.wiley.com/action/showFeed?jc=15516709&type=etoc&feed=rss',
                'category' => 'Cognitive Science',
                'color' => 'bg-green-500',
            ],
            [
                'id' => 'compedu',
                'name' => 'Computers & Education',
                'full_name' => 'Computers and Education',
                'publisher' => 'Elsevier',
                'rss_url' => 'https://rss.sciencedirect.com/publication/science/03601315',
                'category' => 'EdTech',
                'color' => 'bg-orange-500',
            ],
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
                'id' => 'lai',
                'name' => 'Learning & Instruction',
                'full_name' => 'Learning and Instruction',
                'publisher' => 'Elsevier',
                'rss_url' => 'https://rss.sciencedirect.com/publication/science/09594752',
                'category' => 'Learning Sciences',
                'color' => 'bg-teal-500',
            ],
            [
                'id' => 'jecr',
                'name' => 'JECR',
                'full_name' => 'Journal of Educational Computing Research',
                'publisher' => 'SAGE',
                'rss_url' => 'https://journals.sagepub.com/action/showFeed?ui=0&mi=ehikzz&ai=2b4&jc=jeca&type=etoc&feed=rss',
                'category' => 'EdTech',
                'color' => 'bg-indigo-500',
            ],
            [
                'id' => 'etrd',
                'name' => 'ETR&D',
                'full_name' => 'Educational Technology Research and Development',
                'publisher' => 'Springer',
                'rss_url' => 'https://link.springer.com/search.rss?facet-content-type=Article&facet-journal-id=11423',
                'category' => 'EdTech',
                'color' => 'bg-pink-500',
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
