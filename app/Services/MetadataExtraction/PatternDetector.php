<?php

namespace App\Services\MetadataExtraction;

use Illuminate\Support\Facades\Log;

/**
 * RSSフィードのsummary/descriptionからメタデータ埋め込みパターンを自動検出
 */
class PatternDetector
{
    private PatternMatcher $matcher;

    public function __construct(PatternMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    /**
     * サンプルコンテンツからパターンを検出
     *
     * @param array $sampleContents summary/descriptionの配列
     * @return array 検出結果 ['detected_format', 'confidence', 'patterns', 'labels']
     */
    public function detectPatterns(array $sampleContents): array
    {
        // 空のコンテンツを除外
        $sampleContents = array_filter($sampleContents, fn($c) => !empty(trim($c)));

        if (empty($sampleContents)) {
            return [
                'detected_format' => null,
                'confidence' => 0.0,
                'patterns' => [],
                'labels' => [],
            ];
        }

        // HTMLエンティティをデコード
        $sampleContents = array_map(
            fn($c) => html_entity_decode($c, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $sampleContents
        );

        // Step 1: 主要フォーマットを検出
        $formatDetection = $this->detectPrimaryFormat($sampleContents);

        if (!$formatDetection['format']) {
            return [
                'detected_format' => null,
                'confidence' => 0.0,
                'patterns' => [],
                'labels' => [],
            ];
        }

        // Step 2: 検出されたフォーマットに基づいてパターンを生成
        $patterns = $this->buildPatterns($formatDetection['format'], $sampleContents);

        // Step 3: パターンをサンプルで検証して信頼度を調整
        $validation = $this->validatePatterns($patterns, $sampleContents, $formatDetection['format']);

        return [
            'detected_format' => $formatDetection['format'],
            'confidence' => $this->calculateFinalConfidence($formatDetection['confidence'], $validation),
            'patterns' => $patterns,
            'labels' => $formatDetection['labels'] ?? [],
            'validation' => $validation,
        ];
    }

    /**
     * 主要フォーマットを検出
     */
    private function detectPrimaryFormat(array $contents): array
    {
        $formatScores = [];
        $allLabels = [];

        foreach ($contents as $content) {
            foreach (PatternTypes::DETECTION_PATTERNS as $format => $pattern) {
                if (preg_match_all($pattern, $content, $matches)) {
                    $formatScores[$format] = ($formatScores[$format] ?? 0) + count($matches[0]);

                    // ラベルを収集（括弧形式の場合）
                    if ($format === PatternTypes::BRACKET_LABEL) {
                        preg_match_all('/\[\s*([^\[\]]+)\s*\]/u', $content, $labelMatches);
                        foreach ($labelMatches[1] as $label) {
                            $label = trim($label);
                            $allLabels[$label] = ($allLabels[$label] ?? 0) + 1;
                        }
                    } elseif ($format === PatternTypes::CJK_BRACKET) {
                        preg_match_all('/【([^【】]+)】/u', $content, $labelMatches);
                        foreach ($labelMatches[1] as $label) {
                            $label = trim($label);
                            $allLabels[$label] = ($allLabels[$label] ?? 0) + 1;
                        }
                    }
                }
            }
        }

        if (empty($formatScores)) {
            return ['format' => null, 'confidence' => 0.0, 'labels' => []];
        }

        // 最高スコアのフォーマットを選択
        arsort($formatScores);
        $topFormat = array_key_first($formatScores);
        $topScore = $formatScores[$topFormat];

        // 信頼度を算出（サンプル数に対するマッチ率）
        $totalSamples = count($contents);
        $confidence = min(1.0, $topScore / ($totalSamples * 2));

        // よく出現するラベルを抽出
        arsort($allLabels);
        $frequentLabels = array_slice($allLabels, 0, 10, true);

        return [
            'format' => $topFormat,
            'confidence' => $confidence,
            'labels' => $frequentLabels,
        ];
    }

    /**
     * 検出されたフォーマットに基づいてパターンを生成
     */
    private function buildPatterns(string $format, array $contents): array
    {
        $patterns = [];

        switch ($format) {
            case PatternTypes::BRACKET_LABEL:
                $patterns = $this->buildBracketLabelPatterns($contents);
                break;

            case PatternTypes::CJK_BRACKET:
                $patterns = $this->buildCjkBracketPatterns($contents);
                break;

            case PatternTypes::COLON_LABEL:
                $patterns = $this->buildColonLabelPatterns($contents);
                break;

            case PatternTypes::HTML_TAG:
                $patterns = $this->buildHtmlPatterns($contents);
                break;
        }

        // DOIパターンは常に追加（フォールバック用）
        if (!isset($patterns['doi'])) {
            $patterns['doi'] = [
                'type' => PatternTypes::VALUE_PATTERN,
                'regex' => PatternTypes::DOI_PATTERNS['bracket'],
                'flags' => 'i',
            ];
        }

        return $patterns;
    }

    /**
     * 角括弧ラベル形式のパターン生成
     */
    private function buildBracketLabelPatterns(array $contents): array
    {
        $patterns = [];
        $foundLabels = $this->collectBracketLabels($contents);

        foreach ($foundLabels as $label => $count) {
            $field = PatternTypes::matchLabelToField($label);
            if (!$field || isset($patterns[$field])) {
                continue;
            }

            $escapedLabel = preg_quote($label, '/');

            // 次のラベルまで，または末尾までをキャプチャ
            $patterns[$field] = [
                'type' => PatternTypes::BRACKET_LABEL,
                'label' => $label,
                'regex' => '/\[\s*' . $escapedLabel . '\s*\]\s*(.+?)(?=\s*\[|$)/siu',
                'flags' => 'siu',
            ];

            // 著者フィールドには区切り文字情報を追加
            if ($field === 'authors') {
                $patterns[$field]['separator'] = '[,，]';
            }
        }

        return $patterns;
    }

    /**
     * 全角括弧形式のパターン生成
     */
    private function buildCjkBracketPatterns(array $contents): array
    {
        $patterns = [];
        $foundLabels = [];

        foreach ($contents as $content) {
            preg_match_all('/【([^【】]+)】/u', $content, $matches);
            foreach ($matches[1] as $label) {
                $label = trim($label);
                $foundLabels[$label] = ($foundLabels[$label] ?? 0) + 1;
            }
        }

        foreach ($foundLabels as $label => $count) {
            $field = PatternTypes::matchLabelToField($label);
            if (!$field || isset($patterns[$field])) {
                continue;
            }

            $escapedLabel = preg_quote($label, '/');

            $patterns[$field] = [
                'type' => PatternTypes::CJK_BRACKET,
                'label' => $label,
                'regex' => '/【\s*' . $escapedLabel . '\s*】\s*(.+?)(?=\s*【|$)/siu',
                'flags' => 'siu',
            ];

            if ($field === 'authors') {
                $patterns[$field]['separator'] = '[,，・]';
            }
        }

        return $patterns;
    }

    /**
     * コロンラベル形式のパターン生成
     */
    private function buildColonLabelPatterns(array $contents): array
    {
        $patterns = [];
        $foundLabels = [];

        foreach ($contents as $content) {
            preg_match_all('/([\w\p{Han}\p{Hiragana}\p{Katakana}]+)\s*[:：]/u', $content, $matches);
            foreach ($matches[1] as $label) {
                $label = trim($label);
                $foundLabels[$label] = ($foundLabels[$label] ?? 0) + 1;
            }
        }

        foreach ($foundLabels as $label => $count) {
            $field = PatternTypes::matchLabelToField($label);
            if (!$field || isset($patterns[$field])) {
                continue;
            }

            $escapedLabel = preg_quote($label, '/');

            // 次のラベル:まで，または末尾までをキャプチャ
            $patterns[$field] = [
                'type' => PatternTypes::COLON_LABEL,
                'label' => $label,
                'regex' => '/' . $escapedLabel . '\s*[:：]\s*(.+?)(?=[\w\p{Han}\p{Hiragana}\p{Katakana}]+\s*[:：]|$)/siu',
                'flags' => 'siu',
            ];

            if ($field === 'authors') {
                $patterns[$field]['separator'] = '[,，;；]';
            }
        }

        return $patterns;
    }

    /**
     * HTML形式のパターン生成
     */
    private function buildHtmlPatterns(array $contents): array
    {
        $patterns = [];

        // class属性ベースのパターン
        $classPatterns = [
            'title' => '/class\s*=\s*["\'][^"\']*(?:title|article-title)[^"\']*["\']/i',
            'authors' => '/class\s*=\s*["\'][^"\']*(?:author|authors|creator)[^"\']*["\']/i',
            'abstract' => '/class\s*=\s*["\'][^"\']*(?:abstract|summary)[^"\']*["\']/i',
            'doi' => '/class\s*=\s*["\'][^"\']*(?:doi)[^"\']*["\']/i',
        ];

        $sampleContent = implode("\n", $contents);

        foreach ($classPatterns as $field => $detectPattern) {
            if (preg_match($detectPattern, $sampleContent)) {
                $patterns[$field] = [
                    'type' => PatternTypes::HTML_TAG,
                    'regex' => '/<[^>]+class\s*=\s*["\'][^"\']*(?:' . $field . '|article-' . $field . ')[^"\']*["\'][^>]*>(.+?)<\//is',
                    'flags' => 'is',
                ];
            }
        }

        return $patterns;
    }

    /**
     * 角括弧ラベルを収集
     */
    private function collectBracketLabels(array $contents): array
    {
        $labels = [];

        foreach ($contents as $content) {
            preg_match_all('/\[\s*([^\[\]]+)\s*\]/u', $content, $matches);
            foreach ($matches[1] as $label) {
                $label = trim($label);
                $labels[$label] = ($labels[$label] ?? 0) + 1;
            }
        }

        // 出現頻度でソート
        arsort($labels);

        return $labels;
    }

    /**
     * パターンをサンプルで検証
     */
    private function validatePatterns(array $patterns, array $contents, string $format): array
    {
        $validation = [
            'total_samples' => count($contents),
            'successful_extractions' => 0,
            'field_success_rates' => [],
        ];

        $fieldSuccesses = [];

        foreach ($contents as $content) {
            $extracted = $this->matcher->extractWithFormat($content, $format);

            if (!empty($extracted)) {
                $validation['successful_extractions']++;

                foreach ($extracted as $field => $value) {
                    $fieldSuccesses[$field] = ($fieldSuccesses[$field] ?? 0) + 1;
                }
            }
        }

        // フィールドごとの成功率を算出
        foreach ($fieldSuccesses as $field => $count) {
            $validation['field_success_rates'][$field] = $count / count($contents);
        }

        return $validation;
    }

    /**
     * 最終的な信頼度を算出
     */
    private function calculateFinalConfidence(float $formatConfidence, array $validation): float
    {
        $totalSamples = $validation['total_samples'];
        if ($totalSamples === 0) {
            return 0.0;
        }

        // 抽出成功率
        $extractionRate = $validation['successful_extractions'] / $totalSamples;

        // フィールド成功率の平均
        $fieldRates = $validation['field_success_rates'];
        $avgFieldRate = !empty($fieldRates) ? array_sum($fieldRates) / count($fieldRates) : 0;

        // 重要フィールド（DOI, title）の成功率に重み付け
        $importantFieldRate = 0;
        $importantFields = ['doi', 'title', 'authors'];
        $foundImportant = 0;

        foreach ($importantFields as $field) {
            if (isset($fieldRates[$field])) {
                $importantFieldRate += $fieldRates[$field];
                $foundImportant++;
            }
        }

        if ($foundImportant > 0) {
            $importantFieldRate /= $foundImportant;
        }

        // 最終信頼度: フォーマット信頼度 * 抽出成功率 * 重要フィールド率
        $confidence = $formatConfidence * 0.3 + $extractionRate * 0.3 + $importantFieldRate * 0.4;

        return round(min(1.0, $confidence), 3);
    }

    /**
     * 単一コンテンツでクイック検出（フォーマットタイプのみ）
     */
    public function quickDetectFormat(string $content): ?string
    {
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach (PatternTypes::DETECTION_PATTERNS as $format => $pattern) {
            if (preg_match($pattern, $content)) {
                return $format;
            }
        }

        return null;
    }
}
