<?php

namespace App\Services\MetadataExtraction;

/**
 * RSS Summary/Descriptionのメタデータ埋め込みパターン定義
 */
class PatternTypes
{
    // パターン種別
    public const BRACKET_LABEL = 'bracket_label';     // [ Label ] value
    public const COLON_LABEL = 'colon_label';         // Label: value or Label： value
    public const CJK_BRACKET = 'cjk_bracket';         // 【Label】value
    public const HTML_TAG = 'html_tag';               // <tag>value</tag>
    public const VALUE_PATTERN = 'value_pattern';     // ラベルなし，値パターンのみ

    /**
     * パターン種別検出用の正規表現
     */
    public const DETECTION_PATTERNS = [
        self::BRACKET_LABEL => '/\[\s*[^\[\]]+\s*\]/u',
        self::COLON_LABEL => '/(?:^|\s)[\w\p{Han}\p{Hiragana}\p{Katakana}]+\s*[:：]\s*/u',
        self::CJK_BRACKET => '/【[^【】]+】/u',
        self::HTML_TAG => '/<[a-z][a-z0-9]*[^>]*>.*?<\/[a-z][a-z0-9]*>/is',
    ];

    /**
     * フィールドごとのラベル候補（多言語対応）
     */
    public const LABEL_VARIANTS = [
        'title' => [
            'Title', 'タイトル', '題目', '題名', '标题', 'Titre', 'Titel',
            '論文タイトル', '論文題目', 'Paper Title', 'Article Title',
        ],
        'authors' => [
            'Author', 'Authors', '著者', '作者', '執筆者', '著者名',
            'Auteur', 'Autor', 'Autoren', 'By',
        ],
        'doi' => [
            'DOI', 'doi', 'Digital Object Identifier',
        ],
        'published_date' => [
            'Date', 'Published', 'Publication Date', 'Pub Date',
            '公開日', '発行日', '出版日', '掲載日',
            'Published Date', 'Release Date',
        ],
        'abstract' => [
            'Abstract', '概要', 'アブストラクト', '要旨', '摘要',
            'Summary', 'Synopsis', 'Description',
        ],
        'volume' => [
            'Volume', 'Vol', '巻', '巻号',
        ],
        'issue' => [
            'Issue', 'No', 'Number', '号',
        ],
        'pages' => [
            'Pages', 'Page', 'pp', 'ページ', '頁',
        ],
        'keywords' => [
            'Keywords', 'Keyword', 'キーワード', '関連キーワード', 'Tags',
        ],
    ];

    /**
     * DOI抽出用の正規表現パターン
     */
    public const DOI_PATTERNS = [
        // パターン1: [ DOI ] ラベル形式
        'bracket' => '/\[\s*DOI\s*\]\s*(?:https?:\/\/(?:dx\.)?doi\.org\/)?(10\.\d{4,}\/[^\s\]\)]+)/i',
        // パターン2: 【DOI】ラベル形式
        'cjk_bracket' => '/【\s*DOI\s*】\s*(?:https?:\/\/(?:dx\.)?doi\.org\/)?(10\.\d{4,}\/[^\s】]+)/i',
        // パターン3: DOI: または DOI： ラベル形式
        'colon' => '/DOI\s*[:：]\s*(?:https?:\/\/(?:dx\.)?doi\.org\/)?(10\.\d{4,}\/[^\s<>"\']+)/i',
        // パターン4: doi.org URL形式
        'url' => '/(?:https?:\/\/)?(?:dx\.)?doi\.org\/(10\.\d{4,}\/[^\s<>"\']+)/i',
        // パターン5: 生DOIパターン（フォールバック）
        'raw' => '/\b(10\.\d{4,}\/[^\s<>"\']+)/',
    ];

    /**
     * 日付抽出用の正規表現パターン
     */
    public const DATE_PATTERNS = [
        'iso' => '/(\d{4}-\d{2}-\d{2})/',                    // 2025-12-15
        'slash' => '/(\d{4}\/\d{2}\/\d{2})/',               // 2025/12/15
        'jp' => '/(\d{4})年(\d{1,2})月(\d{1,2})日/',        // 2025年12月15日
        'en_long' => '/(\w+)\s+(\d{1,2}),?\s+(\d{4})/',     // December 15, 2025
        'en_short' => '/(\d{1,2})\s+(\w+)\s+(\d{4})/',      // 15 December 2025
    ];

    /**
     * 著者区切り文字のパターン
     */
    public const AUTHOR_SEPARATORS = [
        '/\s*[,，]\s*/u',           // カンマ（半角・全角）
        '/\s*[;；]\s*/u',           // セミコロン（半角・全角）
        '/\s*[・]\s*/u',            // 中点
        '/\s+and\s+/i',            // "and"
        '/\s*&\s*/',               // アンパサンド
    ];

    /**
     * 抽出対象フィールドの優先順位
     */
    public const FIELD_PRIORITY = [
        'doi',              // DOIは最優先（論文識別に重要）
        'title',            // タイトル
        'authors',          // 著者
        'published_date',   // 公開日
        'abstract',         // 概要
        'volume',
        'issue',
        'pages',
        'keywords',
    ];

    /**
     * 指定されたラベルがどのフィールドに対応するか判定
     *
     * @param string $label 検出されたラベル
     * @return string|null フィールド名（title, authors, doi等）またはnull
     */
    public static function matchLabelToField(string $label): ?string
    {
        $label = trim($label);

        foreach (self::LABEL_VARIANTS as $field => $variants) {
            foreach ($variants as $variant) {
                // 完全一致（大文字小文字無視）
                if (strcasecmp($label, $variant) === 0) {
                    return $field;
                }
                // 部分一致（ラベルに候補が含まれる）
                if (mb_stripos($label, $variant) !== false) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * パターン種別の優先順位を取得
     * （複数パターンが検出された場合の選択基準）
     *
     * @param string $type パターン種別
     * @return int 優先順位（小さいほど高優先）
     */
    public static function getPatternPriority(string $type): int
    {
        $priorities = [
            self::BRACKET_LABEL => 1,
            self::CJK_BRACKET => 2,
            self::COLON_LABEL => 3,
            self::HTML_TAG => 4,
            self::VALUE_PATTERN => 5,
        ];

        return $priorities[$type] ?? 99;
    }
}
