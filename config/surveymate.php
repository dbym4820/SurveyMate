<?php

/*
|--------------------------------------------------------------------------
| Default Journal: IJAIED
|--------------------------------------------------------------------------
| International Journal of Artificial Intelligence in Education
*/
$defaultJournals = [
    [
        'id' => 'ijaied',
        'name' => 'IJAIED',
        'full_name' => 'International Journal of Artificial Intelligence in Education',
        'rss_url' => 'https://link.springer.com/search.rss?facet-content-type=Article&facet-journal-id=40593',
        'color' => 'bg-blue-500',
    ],
];

/*
|--------------------------------------------------------------------------
| Additional Journals from .env (optional)
|--------------------------------------------------------------------------
| Format: NAME|RSS_URL,NAME2|RSS_URL2,...
*/
$envJournals = env('DEFAULT_JOURNALS', '');

if (!empty($envJournals)) {
    $entries = array_filter(array_map('trim', explode(',', $envJournals)));
    foreach ($entries as $entry) {
        $parts = explode('|', $entry, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $defaultJournals[] = [
                'id' => strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name)),
                'name' => $name,
                'rss_url' => trim($parts[1]),
                'color' => 'bg-gray-500',
            ];
        }
    }
}

return [

    /*
    |--------------------------------------------------------------------------
    | SurveyMate Application Configuration
    |--------------------------------------------------------------------------
    */

    'name' => 'SurveyMate',
    'version' => '1.0.0',
    'developer' => 'Tomoki Aburatani',
    'description' => 'Academic Paper RSS Aggregation and AI Summary System',

    /*
    |--------------------------------------------------------------------------
    | Default Journals for New Users
    |--------------------------------------------------------------------------
    |
    | IJAIED is always included as default.
    | Additional journals can be set via .env: DEFAULT_JOURNALS="NAME|RSS_URL,NAME2|RSS_URL2"
    |
    */

    'default_journals' => $defaultJournals,

];
