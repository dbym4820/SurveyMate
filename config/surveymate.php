<?php

/*
|--------------------------------------------------------------------------
| Default Journals from .env (optional)
|--------------------------------------------------------------------------
| Format: NAME|RSS_URL;NAME2|RSS_URL2;...
| (セミコロン区切り，ジャーナル名に空白を含むことが可能)
| Example: "International Journal of Artificial Intelligence in Education|https://link.springer.com/search.rss?facet-journal-id=40593"
*/
$defaultJournals = [];
$envJournals = env('DEFAULT_JOURNALS', '');

// 利用可能なテーマカラー（Tailwind CSS）
$journalColors = [
    'bg-red-500',
    'bg-orange-500',
    'bg-amber-500',
    'bg-yellow-500',
    'bg-lime-500',
    'bg-green-500',
    'bg-emerald-500',
    'bg-teal-500',
    'bg-cyan-500',
    'bg-sky-500',
    'bg-blue-500',
    'bg-indigo-500',
    'bg-violet-500',
    'bg-purple-500',
    'bg-fuchsia-500',
    'bg-pink-500',
    'bg-rose-500',
];

if (!empty($envJournals)) {
    // セミコロンで区切る（ジャーナル名に空白やカンマを含むことが可能）
    $entries = array_filter(array_map('trim', explode(';', $envJournals)));
    $colorIndex = 0;
    foreach ($entries as $entry) {
        $parts = explode('|', $entry, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $defaultJournals[] = [
                'id' => strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name)),
                'name' => $name,
                'rss_url' => trim($parts[1]),
                'color' => $journalColors[$colorIndex % count($journalColors)],
            ];
            $colorIndex++;
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
    | Set via .env: DEFAULT_JOURNALS="NAME|RSS_URL;NAME2|RSS_URL2"
    | (セミコロン区切り，ジャーナル名に空白を含むことが可能)
    | Leave empty for no default journals.
    |
    */

    'default_journals' => $defaultJournals,

    /*
    |--------------------------------------------------------------------------
    | Full Text Fetching Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for automatic full text extraction from academic papers.
    | Unpaywall API requires an email for identification (rate limiting).
    |
    */

    'full_text' => [
        'enabled' => env('FULLTEXT_FETCH_ENABLED', true),
        'unpaywall_email' => env('UNPAYWALL_EMAIL', ''),
        'max_text_length' => env('FULLTEXT_MAX_LENGTH', 100000),
        'max_pdf_size' => env('FULLTEXT_MAX_PDF_SIZE', 1024 * 1024 * 1024), // 1GB default
        'pdf_memory_limit' => env('FULLTEXT_PDF_MEMORY_LIMIT', '2G'), // Memory limit for PDF parsing
        'timeout' => env('FULLTEXT_TIMEOUT', 30),
        'retry_failed' => env('FULLTEXT_RETRY_FAILED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default User Settings
    |--------------------------------------------------------------------------
    |
    | デフォルトの要約テンプレートと調査観点設定
    | config/generative_ai_settings/*.txt で管理（Git管理可能）
    |
    */

    'defaults' => [
        'summary_template' => file_exists(config_path('generative_ai_settings/summary_template.txt'))
            ? trim(file_get_contents(config_path('generative_ai_settings/summary_template.txt')))
            : '',
        'research_fields' => file_exists(config_path('generative_ai_settings/research_fields.txt'))
            ? trim(file_get_contents(config_path('generative_ai_settings/research_fields.txt')))
            : '',
        'summary_perspective' => file_exists(config_path('generative_ai_settings/summary_perspective.txt'))
            ? trim(file_get_contents(config_path('generative_ai_settings/summary_perspective.txt')))
            : '',
        'reading_focus' => file_exists(config_path('generative_ai_settings/reading_focus.txt'))
            ? trim(file_get_contents(config_path('generative_ai_settings/reading_focus.txt')))
            : '',
    ],

];
