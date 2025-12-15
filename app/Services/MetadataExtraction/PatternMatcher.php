<?php

namespace App\Services\MetadataExtraction;

use Illuminate\Support\Facades\Log;

/**
 * 保存済みパターンを適用してメタデータを抽出
 */
class PatternMatcher
{
    /**
     * パターンを適用してメタデータを抽出
     *
     * @param string $content 抽出対象のテキスト（summary/description）
     * @param array $patterns 適用するパターン定義
     * @return array 抽出されたメタデータ
     */
    public function extractMetadata(string $content, array $patterns): array
    {
        $result = [];

        // HTMLエンティティをデコード
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ($patterns as $field => $pattern) {
            if (!isset($pattern['regex'])) {
                continue;
            }

            $value = $this->extractField($content, $pattern, $field);
            if ($value !== null) {
                $result[$field] = $value;
            }
        }

        // DOIが抽出されなかった場合，汎用パターンで再試行
        if (empty($result['doi'])) {
            $doi = $this->extractDoiFallback($content);
            if ($doi) {
                $result['doi'] = $doi;
            }
        }

        return $result;
    }

    /**
     * 単一フィールドの抽出
     */
    private function extractField(string $content, array $pattern, string $field): mixed
    {
        $regex = $pattern['regex'];
        $flags = $pattern['flags'] ?? '';

        // フラグを正規表現に付加
        if ($flags && !str_ends_with($regex, $flags)) {
            // 既存の修飾子を確認
            if (preg_match('/\/[a-z]*$/i', $regex)) {
                // 既存の修飾子に追加
                $regex = preg_replace('/\/([a-z]*)$/i', '/$1' . $flags, $regex);
            }
        }

        try {
            if (preg_match($regex, $content, $matches)) {
                $rawValue = $matches[1] ?? $matches[0];
                return $this->cleanValue($rawValue, $field, $pattern);
            }
        } catch (\Exception $e) {
            Log::warning("Pattern match failed for field {$field}", [
                'regex' => $regex,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * 抽出した値をフィールドに応じてクリーンアップ
     */
    private function cleanValue(string $value, string $field, array $pattern): mixed
    {
        // HTMLタグを除去
        $value = strip_tags($value);
        // 余分な空白を除去
        $value = preg_replace('/\s+/', ' ', trim($value));

        switch ($field) {
            case 'doi':
                return $this->cleanDoi($value);

            case 'authors':
                return $this->parseAuthors($value, $pattern['separator'] ?? null);

            case 'published_date':
                return $this->parseDate($value, $pattern['date_format'] ?? null);

            case 'title':
            case 'abstract':
                return $this->cleanText($value);

            default:
                return $value;
        }
    }

    /**
     * DOIをクリーンアップ
     */
    private function cleanDoi(string $doi): string
    {
        // URLプレフィックスを除去
        $doi = preg_replace('/^https?:\/\/(?:dx\.)?doi\.org\//', '', $doi);
        // 末尾の不要な文字を除去
        $doi = rtrim($doi, '.,;:)]\'"');
        return $doi;
    }

    /**
     * 著者文字列をパースして配列に変換
     */
    private function parseAuthors(string $authorsString, ?string $separatorPattern = null): array
    {
        if ($separatorPattern) {
            $authors = preg_split('/' . $separatorPattern . '/u', $authorsString);
        } else {
            // デフォルト: 複数の区切り文字を試行
            $authors = null;
            foreach (PatternTypes::AUTHOR_SEPARATORS as $separator) {
                $split = preg_split($separator, $authorsString);
                if (count($split) > 1) {
                    $authors = $split;
                    break;
                }
            }

            if ($authors === null) {
                $authors = [$authorsString];
            }
        }

        // 各著者名をクリーンアップ
        return array_values(array_filter(array_map(function ($author) {
            $author = trim($author);
            // 空の括弧や番号を除去
            $author = preg_replace('/\s*\([^)]*\)\s*/', '', $author);
            $author = preg_replace('/^\d+\.\s*/', '', $author);
            return trim($author);
        }, $authors)));
    }

    /**
     * 日付文字列をパースしてY-m-d形式に変換
     */
    private function parseDate(string $dateString, ?string $format = null): ?string
    {
        // 指定フォーマットがある場合
        if ($format) {
            $date = \DateTime::createFromFormat($format, $dateString);
            if ($date) {
                return $date->format('Y-m-d');
            }
        }

        // ISO形式（2025-12-15）
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $dateString, $m)) {
            return $m[0];
        }

        // スラッシュ形式（2025/12/15）
        if (preg_match('/(\d{4})\/(\d{2})\/(\d{2})/', $dateString, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        // 日本語形式（2025年12月15日）
        if (preg_match('/(\d{4})年(\d{1,2})月(\d{1,2})日/', $dateString, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }

        // DateTime汎用パース
        try {
            $date = new \DateTime($dateString);
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * テキストをクリーンアップ
     */
    private function cleanText(string $text): string
    {
        // 連続する空白を単一スペースに
        $text = preg_replace('/\s+/', ' ', $text);
        // 前後の空白を除去
        $text = trim($text);
        return $text;
    }

    /**
     * DOI抽出のフォールバック（複数パターンを試行）
     */
    private function extractDoiFallback(string $content): ?string
    {
        foreach (PatternTypes::DOI_PATTERNS as $name => $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $doi = $matches[1];
                return $this->cleanDoi($doi);
            }
        }
        return null;
    }

    /**
     * 特定パターンタイプでのメタデータ一括抽出
     * （フォーマットが判明している場合に使用）
     */
    public function extractWithFormat(string $content, string $formatType, array $detectedLabels = []): array
    {
        $result = [];

        switch ($formatType) {
            case PatternTypes::BRACKET_LABEL:
                $result = $this->extractBracketFormat($content, $detectedLabels);
                break;

            case PatternTypes::CJK_BRACKET:
                $result = $this->extractCjkBracketFormat($content, $detectedLabels);
                break;

            case PatternTypes::COLON_LABEL:
                $result = $this->extractColonFormat($content, $detectedLabels);
                break;

            case PatternTypes::HTML_TAG:
                $result = $this->extractHtmlFormat($content);
                break;
        }

        // DOIフォールバック
        if (empty($result['doi'])) {
            $doi = $this->extractDoiFallback($content);
            if ($doi) {
                $result['doi'] = $doi;
            }
        }

        return $result;
    }

    /**
     * 角括弧ラベル形式の抽出 [ Label ] value
     */
    private function extractBracketFormat(string $content, array $knownLabels = []): array
    {
        $result = [];

        // すべての [ Label ] パターンを検出
        preg_match_all('/\[\s*([^\[\]]+)\s*\]\s*([^\[]+?)(?=\s*\[|$)/su', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $label = trim($match[1]);
            $value = trim($match[2]);

            // ラベルからフィールドを特定
            $field = $knownLabels[$label] ?? PatternTypes::matchLabelToField($label);

            if ($field && $value) {
                $result[$field] = $this->cleanValue($value, $field, []);
            }
        }

        return $result;
    }

    /**
     * 全角括弧形式の抽出 【Label】value
     */
    private function extractCjkBracketFormat(string $content, array $knownLabels = []): array
    {
        $result = [];

        preg_match_all('/【\s*([^【】]+)\s*】\s*([^【]+?)(?=\s*【|$)/su', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $label = trim($match[1]);
            $value = trim($match[2]);

            $field = $knownLabels[$label] ?? PatternTypes::matchLabelToField($label);

            if ($field && $value) {
                $result[$field] = $this->cleanValue($value, $field, []);
            }
        }

        return $result;
    }

    /**
     * コロンラベル形式の抽出 Label: value
     */
    private function extractColonFormat(string $content, array $knownLabels = []): array
    {
        $result = [];

        // 改行またはラベルで区切られたコロン形式を検出
        preg_match_all('/([\w\p{Han}\p{Hiragana}\p{Katakana}]+)\s*[:：]\s*(.+?)(?=(?:[\w\p{Han}\p{Hiragana}\p{Katakana}]+\s*[:：])|$)/su', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $label = trim($match[1]);
            $value = trim($match[2]);

            $field = $knownLabels[$label] ?? PatternTypes::matchLabelToField($label);

            if ($field && $value) {
                $result[$field] = $this->cleanValue($value, $field, []);
            }
        }

        return $result;
    }

    /**
     * HTML形式の抽出
     */
    private function extractHtmlFormat(string $content): array
    {
        $result = [];

        // class属性からフィールドを推測
        $classPatterns = [
            'title' => '/<[^>]+class\s*=\s*["\'][^"\']*(?:title|article-title)[^"\']*["\'][^>]*>(.+?)<\//is',
            'authors' => '/<[^>]+class\s*=\s*["\'][^"\']*(?:author|authors|creator)[^"\']*["\'][^>]*>(.+?)<\//is',
            'abstract' => '/<[^>]+class\s*=\s*["\'][^"\']*(?:abstract|summary|description)[^"\']*["\'][^>]*>(.+?)<\//is',
            'doi' => '/<[^>]+class\s*=\s*["\'][^"\']*(?:doi)[^"\']*["\'][^>]*>(.+?)<\//is',
        ];

        foreach ($classPatterns as $field => $pattern) {
            if (preg_match($pattern, $content, $match)) {
                $value = strip_tags($match[1]);
                $result[$field] = $this->cleanValue($value, $field, []);
            }
        }

        return $result;
    }
}
