<?php

namespace App\Services\MetadataExtraction;

use App\Models\Journal;
use Illuminate\Support\Facades\Log;

/**
 * メタデータ抽出の統合サービス
 * RSSフィードのsummary/descriptionから論文メタデータを抽出
 */
class MetadataExtractorService
{
    private PatternDetector $detector;
    private PatternMatcher $matcher;

    /** @var float パターン適用の最小信頼度閾値 */
    private const MIN_CONFIDENCE_THRESHOLD = 0.3;

    /** @var float パターン保存の最小信頼度閾値 */
    private const SAVE_CONFIDENCE_THRESHOLD = 0.5;

    public function __construct(PatternDetector $detector, PatternMatcher $matcher)
    {
        $this->detector = $detector;
        $this->matcher = $matcher;
    }

    /**
     * Description/Summaryからメタデータを抽出
     *
     * @param string|null $content 抽出対象のテキスト
     * @param Journal $journal 論文誌（保存済みパターンの参照用）
     * @return array 抽出されたメタデータ ['title', 'authors', 'doi', 'published_date', 'abstract']
     */
    public function extractFromDescription(?string $content, Journal $journal): array
    {
        if (empty($content)) {
            return [];
        }

        // 1. 保存済みパターンがあるか確認
        $storedConfig = $journal->getSummaryParsingConfig();

        if ($storedConfig && $this->isValidStoredConfig($storedConfig)) {
            // 保存済みパターンを使用
            $result = $this->extractWithStoredPatterns($content, $storedConfig);

            if (!empty($result)) {
                Log::debug("Extracted metadata using stored patterns", [
                    'journal_id' => $journal->id,
                    'format' => $storedConfig['detected_format'] ?? 'unknown',
                    'fields' => array_keys($result),
                ]);
                return $result;
            }
        }

        // 2. パターンがない/信頼度低い場合はクイック検出を試行
        $format = $this->detector->quickDetectFormat($content);

        if ($format) {
            $result = $this->matcher->extractWithFormat($content, $format);

            if (!empty($result)) {
                Log::debug("Extracted metadata using quick detection", [
                    'journal_id' => $journal->id,
                    'format' => $format,
                    'fields' => array_keys($result),
                ]);
                return $result;
            }
        }

        return [];
    }

    /**
     * 保存済みパターンでメタデータを抽出
     */
    private function extractWithStoredPatterns(string $content, array $config): array
    {
        $format = $config['detected_format'] ?? null;
        $patterns = $config['patterns'] ?? [];

        if (empty($patterns)) {
            // パターン定義がない場合はフォーマットベースで抽出
            if ($format) {
                return $this->matcher->extractWithFormat($content, $format);
            }
            return [];
        }

        // パターン定義を使用して抽出
        return $this->matcher->extractMetadata($content, $patterns);
    }

    /**
     * 保存済み設定の有効性を確認
     */
    private function isValidStoredConfig(?array $config): bool
    {
        if (!$config) {
            return false;
        }

        // enabledフラグの確認
        if (isset($config['enabled']) && !$config['enabled']) {
            return false;
        }

        // 信頼度の確認
        $confidence = $config['confidence'] ?? 0;
        if ($confidence < self::MIN_CONFIDENCE_THRESHOLD) {
            return false;
        }

        // フォーマットまたはパターンの存在確認
        if (empty($config['detected_format']) && empty($config['patterns'])) {
            return false;
        }

        return true;
    }

    /**
     * フィード取得時にパターンを検出・保存
     *
     * @param Journal $journal 論文誌
     * @param array $sampleItems SimplePieアイテムの配列
     * @return array 検出結果
     */
    public function analyzeAndStorePatterns(Journal $journal, array $sampleItems): array
    {
        // サンプルからdescription/summaryを収集
        $contents = [];
        foreach ($sampleItems as $item) {
            $description = $item->get_description();
            if ($description) {
                $contents[] = $description;
            }
        }

        if (empty($contents)) {
            Log::debug("No description content found for pattern analysis", [
                'journal_id' => $journal->id,
            ]);
            return [
                'success' => false,
                'reason' => 'no_content',
            ];
        }

        // パターン検出を実行
        $detection = $this->detector->detectPatterns($contents);

        Log::info("Pattern detection completed", [
            'journal_id' => $journal->id,
            'format' => $detection['detected_format'],
            'confidence' => $detection['confidence'],
            'patterns_count' => count($detection['patterns']),
        ]);

        // 信頼度が閾値を超えた場合のみ保存
        if ($detection['confidence'] >= self::SAVE_CONFIDENCE_THRESHOLD) {
            $this->savePatternConfig($journal, $detection);

            return [
                'success' => true,
                'detected_format' => $detection['detected_format'],
                'confidence' => $detection['confidence'],
                'patterns' => $detection['patterns'],
            ];
        }

        return [
            'success' => false,
            'reason' => 'low_confidence',
            'detected_format' => $detection['detected_format'],
            'confidence' => $detection['confidence'],
        ];
    }

    /**
     * パターン設定をJournalに保存
     */
    private function savePatternConfig(Journal $journal, array $detection): void
    {
        $config = [
            'enabled' => true,
            'detected_format' => $detection['detected_format'],
            'confidence' => $detection['confidence'],
            'detected_at' => now()->toIso8601String(),
            'patterns' => $detection['patterns'],
            'labels' => $detection['labels'] ?? [],
            'pattern_source' => 'auto_detected',
        ];

        $journal->updateSummaryParsingConfig($config);

        Log::info("Saved pattern config for journal", [
            'journal_id' => $journal->id,
            'format' => $detection['detected_format'],
        ]);
    }

    /**
     * 手動でパターン分析を実行（APIエンドポイント用）
     *
     * @param Journal $journal 論文誌
     * @param bool $forceReanalyze 既存設定を上書きするか
     * @return array 分析結果
     */
    public function analyzeJournalPatterns(Journal $journal, bool $forceReanalyze = false): array
    {
        // 既存設定の確認
        if (!$forceReanalyze) {
            $existingConfig = $journal->getSummaryParsingConfig();
            if ($existingConfig && ($existingConfig['confidence'] ?? 0) >= self::SAVE_CONFIDENCE_THRESHOLD) {
                return [
                    'success' => true,
                    'source' => 'existing',
                    'detected_format' => $existingConfig['detected_format'] ?? null,
                    'confidence' => $existingConfig['confidence'] ?? 0,
                    'patterns' => $existingConfig['patterns'] ?? [],
                ];
            }
        }

        // RSSフィードを取得してサンプルを収集
        try {
            $feed = new \SimplePie\SimplePie();
            $feed->set_feed_url($journal->rss_url);
            $feed->set_timeout(30);
            $feed->enable_cache(false);
            $feed->init();

            if ($feed->error()) {
                return [
                    'success' => false,
                    'error' => 'feed_fetch_failed',
                    'message' => $feed->error(),
                ];
            }

            $items = $feed->get_items(0, 5);

            if (empty($items)) {
                return [
                    'success' => false,
                    'error' => 'no_items',
                ];
            }

            return $this->analyzeAndStorePatterns($journal, $items);

        } catch (\Exception $e) {
            Log::error("Pattern analysis failed", [
                'journal_id' => $journal->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * パターン設定をクリア
     */
    public function clearPatternConfig(Journal $journal): void
    {
        $journal->clearSummaryParsingConfig();

        Log::info("Cleared pattern config for journal", [
            'journal_id' => $journal->id,
        ]);
    }

    /**
     * 複数のコンテンツに対して一括抽出
     * （デバッグ/テスト用）
     */
    public function batchExtract(array $contents, Journal $journal): array
    {
        $results = [];

        foreach ($contents as $index => $content) {
            $results[$index] = $this->extractFromDescription($content, $journal);
        }

        return $results;
    }
}
