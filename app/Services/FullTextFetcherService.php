<?php

namespace App\Services;

use App\Models\Paper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;

class FullTextFetcherService
{
    private const UNPAYWALL_API_URL = 'https://api.unpaywall.org/v2/';

    private ?string $unpaywallEmail;
    private int $timeout;
    private int $maxTextLength;

    public function __construct()
    {
        $this->unpaywallEmail = config('surveymate.full_text.unpaywall_email');
        $this->timeout = config('surveymate.full_text.timeout', 30);
        $this->maxTextLength = config('surveymate.full_text.max_text_length', 100000);
    }

    /**
     * 論文の本文を取得
     *
     * @param Paper $paper
     * @return array ['success' => bool, 'source' => string|null, 'text' => string|null, 'pdf_url' => string|null, 'error' => string|null]
     */
    public function fetchFullText(Paper $paper): array
    {
        // DOIがない場合はURL直接アクセスを試行
        if (empty($paper->doi)) {
            if (!empty($paper->url)) {
                $result = $this->extractTextFromHtml($paper->url);
                if ($result['success']) {
                    return [
                        'success' => true,
                        'source' => 'html_scrape',
                        'text' => $this->truncateText($result['text']),
                        'pdf_url' => null,
                        'error' => null,
                    ];
                }
            }
            return [
                'success' => false,
                'source' => null,
                'text' => null,
                'pdf_url' => null,
                'error' => 'No DOI or accessible URL available',
            ];
        }

        // 1. Unpaywall APIでPDF URLを取得
        $pdfUrl = $this->getPdfUrlFromUnpaywall($paper->doi);

        if ($pdfUrl) {
            // 2. PDFをダウンロードしてテキスト抽出
            $result = $this->extractTextFromPdf($pdfUrl);
            if ($result['success']) {
                return [
                    'success' => true,
                    'source' => 'unpaywall_pdf',
                    'text' => $this->truncateText($result['text']),
                    'pdf_url' => $pdfUrl,
                    'error' => null,
                ];
            }
            Log::warning("PDF extraction failed for DOI {$paper->doi}: {$result['error']}");
        }

        // 3. フォールバック: 論文URLからHTML本文抽出
        if (!empty($paper->url)) {
            $result = $this->extractTextFromHtml($paper->url);
            if ($result['success']) {
                return [
                    'success' => true,
                    'source' => 'html_scrape',
                    'text' => $this->truncateText($result['text']),
                    'pdf_url' => null,
                    'error' => null,
                ];
            }
            Log::warning("HTML extraction failed for URL {$paper->url}: {$result['error']}");
        }

        return [
            'success' => false,
            'source' => null,
            'text' => null,
            'pdf_url' => null,
            'error' => 'All extraction methods failed',
        ];
    }

    /**
     * Unpaywall APIからPDF URLを取得
     */
    private function getPdfUrlFromUnpaywall(string $doi): ?string
    {
        if (empty($this->unpaywallEmail)) {
            Log::warning('Unpaywall email not configured');
            return null;
        }

        try {
            // DOIをURLエンコード
            $encodedDoi = urlencode($doi);
            $url = self::UNPAYWALL_API_URL . $encodedDoi . '?email=' . urlencode($this->unpaywallEmail);

            $response = Http::timeout($this->timeout)->get($url);

            if (!$response->successful()) {
                Log::warning("Unpaywall API error for DOI {$doi}: " . $response->status());
                return null;
            }

            $data = $response->json();

            // best_oa_locationからPDF URLを取得
            $bestLocation = $data['best_oa_location'] ?? null;
            if ($bestLocation && !empty($bestLocation['url_for_pdf'])) {
                return $bestLocation['url_for_pdf'];
            }

            // oa_locationsから探す
            $locations = $data['oa_locations'] ?? [];
            foreach ($locations as $location) {
                if (!empty($location['url_for_pdf'])) {
                    return $location['url_for_pdf'];
                }
            }

            return null;

        } catch (\Exception $e) {
            Log::error("Unpaywall API exception for DOI {$doi}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * PDFからテキストを抽出
     */
    private function extractTextFromPdf(string $pdfUrl): array
    {
        try {
            // PDFをダウンロード
            $response = Http::timeout($this->timeout * 2)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; SurveyMate/1.0; Academic Research)',
                ])
                ->get($pdfUrl);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'text' => null,
                    'error' => 'Failed to download PDF: ' . $response->status(),
                ];
            }

            $pdfContent = $response->body();

            // Content-Typeチェック
            $contentType = $response->header('Content-Type');
            if ($contentType && !str_contains($contentType, 'pdf') && !str_contains($contentType, 'octet-stream')) {
                return [
                    'success' => false,
                    'text' => null,
                    'error' => 'Not a PDF file: ' . $contentType,
                ];
            }

            // PDFパーサーでテキスト抽出
            $parser = new PdfParser();
            $pdf = $parser->parseContent($pdfContent);
            $text = $pdf->getText();

            // テキストが短すぎる場合は失敗とみなす
            if (strlen($text) < 500) {
                return [
                    'success' => false,
                    'text' => null,
                    'error' => 'Extracted text too short (possibly scanned PDF)',
                ];
            }

            // クリーンアップ
            $text = $this->cleanExtractedText($text);

            return [
                'success' => true,
                'text' => $text,
                'error' => null,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'text' => null,
                'error' => 'PDF parsing error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * HTMLページから本文を抽出
     */
    private function extractTextFromHtml(string $url): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; SurveyMate/1.0; Academic Research)',
                ])
                ->get($url);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'text' => null,
                    'error' => 'Failed to fetch HTML: ' . $response->status(),
                ];
            }

            $html = $response->body();

            // HTMLから本文を抽出
            $text = $this->extractMainContent($html);

            if (strlen($text) < 500) {
                return [
                    'success' => false,
                    'text' => null,
                    'error' => 'Extracted content too short',
                ];
            }

            return [
                'success' => true,
                'text' => $text,
                'error' => null,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'text' => null,
                'error' => 'HTML extraction error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * HTMLから本文コンテンツを抽出
     */
    private function extractMainContent(string $html): string
    {
        // scriptとstyleタグを除去
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
        $html = preg_replace('/<noscript\b[^>]*>(.*?)<\/noscript>/is', '', $html);
        $html = preg_replace('/<nav\b[^>]*>(.*?)<\/nav>/is', '', $html);
        $html = preg_replace('/<header\b[^>]*>(.*?)<\/header>/is', '', $html);
        $html = preg_replace('/<footer\b[^>]*>(.*?)<\/footer>/is', '', $html);

        // 学術論文で一般的な本文セクションを探す
        $patterns = [
            '/<article[^>]*>(.*?)<\/article>/is',
            '/<div[^>]*class="[^"]*(?:article-body|full-text|paper-content|main-content|c-article-body)[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<main[^>]*>(.*?)<\/main>/is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $text = strip_tags($matches[1]);
                $text = $this->cleanExtractedText($text);
                if (strlen($text) > 500) {
                    return $text;
                }
            }
        }

        // フォールバック: body全体からテキスト抽出
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches)) {
            $text = strip_tags($matches[1]);
            return $this->cleanExtractedText($text);
        }

        return $this->cleanExtractedText(strip_tags($html));
    }

    /**
     * 抽出テキストのクリーンアップ
     */
    private function cleanExtractedText(string $text): string
    {
        // 改行の正規化
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // 連続する空白を1つに
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // 連続する改行を2つまでに
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // 各行のトリム
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $text = implode("\n", $lines);

        // HTMLエンティティをデコード
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($text);
    }

    /**
     * テキストを最大長に切り詰め
     */
    private function truncateText(string $text): string
    {
        if (mb_strlen($text) <= $this->maxTextLength) {
            return $text;
        }

        // 段落境界で切る
        $truncated = mb_substr($text, 0, $this->maxTextLength);
        $lastParagraph = mb_strrpos($truncated, "\n\n");

        if ($lastParagraph !== false && $lastParagraph > $this->maxTextLength * 0.8) {
            return mb_substr($truncated, 0, $lastParagraph);
        }

        return $truncated . "\n\n[... truncated ...]";
    }

    /**
     * 本文が未取得の論文数を取得
     */
    public function countPendingPapers(): int
    {
        return Paper::whereNull('full_text')
            ->whereNotNull('doi')
            ->count();
    }
}
