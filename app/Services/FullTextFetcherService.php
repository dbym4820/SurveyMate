<?php

namespace App\Services;

use App\Models\Paper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;
use Smalot\PdfParser\Config as PdfConfig;

class FullTextFetcherService
{
    private const UNPAYWALL_API_URL = 'https://api.unpaywall.org/v2/';

    private ?string $unpaywallEmail;
    private int $timeout;
    private int $maxTextLength;
    private int $maxPdfSize;
    private string $pdfMemoryLimit;

    public function __construct()
    {
        $this->unpaywallEmail = config('surveymate.full_text.unpaywall_email');
        $this->timeout = config('surveymate.full_text.timeout', 30);
        $this->maxTextLength = config('surveymate.full_text.max_text_length', 100000);
        $this->maxPdfSize = config('surveymate.full_text.max_pdf_size', 1024 * 1024 * 1024); // 1GB default
        $this->pdfMemoryLimit = config('surveymate.full_text.pdf_memory_limit', '2G');
    }

    /**
     * 論文の本文を取得
     *
     * @param Paper $paper
     * @return array ['success' => bool, 'source' => string|null, 'text' => string|null, 'pdf_url' => string|null, 'pdf_path' => string|null, 'error' => string|null]
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
                        'pdf_path' => null,
                        'error' => null,
                    ];
                }
            }
            return [
                'success' => false,
                'source' => null,
                'text' => null,
                'pdf_url' => null,
                'pdf_path' => null,
                'error' => 'No DOI or accessible URL available',
            ];
        }

        // 1. Unpaywall APIでPDF URLを取得
        $pdfUrl = $this->getPdfUrlFromUnpaywall($paper->doi);

        if ($pdfUrl) {
            // 2. PDFをダウンロードしてテキスト抽出（PDFも保存）
            $result = $this->extractTextFromPdf($pdfUrl, $paper);
            if ($result['success']) {
                return [
                    'success' => true,
                    'source' => 'unpaywall_pdf',
                    'text' => $this->truncateText($result['text']),
                    'pdf_url' => $pdfUrl,
                    'pdf_path' => $result['pdf_path'] ?? null,
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
                    'pdf_path' => null,
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
            'pdf_path' => null,
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
     * PDFからテキストを抽出し，PDFファイルも保存
     */
    private function extractTextFromPdf(string $pdfUrl, ?Paper $paper = null): array
    {
        $tempFile = null;

        try {
            // 一時ファイルを作成（メモリ節約のため）
            $tempFile = tempnam(sys_get_temp_dir(), 'surveymate_pdf_');
            if ($tempFile === false) {
                return [
                    'success' => false,
                    'text' => null,
                    'pdf_path' => null,
                    'error' => 'Failed to create temp file',
                ];
            }

            // PDFをストリーミングダウンロード（大きなファイル対応）
            $downloadResult = $this->downloadPdfToFile($pdfUrl, $tempFile);
            if (!$downloadResult['success']) {
                @unlink($tempFile);
                return array_merge($downloadResult, ['pdf_path' => null]);
            }

            $pdfSize = filesize($tempFile);

            // PDFサイズチェック
            if ($pdfSize > $this->maxPdfSize) {
                @unlink($tempFile);
                Log::info("PDF too large, skipping: {$pdfUrl} ({$pdfSize} bytes)");
                return [
                    'success' => false,
                    'text' => null,
                    'pdf_path' => null,
                    'error' => 'PDF too large: ' . round($pdfSize / 1024 / 1024, 2) . 'MB (max: ' . round($this->maxPdfSize / 1024 / 1024) . 'MB)',
                ];
            }

            // PDFをストレージに保存
            $pdfPath = null;
            if ($paper !== null) {
                $pdfPath = $this->savePdfToStorage($tempFile, $paper);
            }

            // メモリ制限を一時的に増加（PDFパース用）
            $originalMemoryLimit = ini_get('memory_limit');
            ini_set('memory_limit', $this->pdfMemoryLimit);

            $text = null;
            $parseError = null;

            try {
                // PDFパーサー設定（メモリ最適化）
                $config = new PdfConfig();
                $config->setRetainImageContent(false); // 画像データを保持しない
                $config->setDecodeMemoryLimit(0); // デコードメモリ制限を無効化（独自で管理）
                $config->setFontSpaceLimit(-60); // フォントスペース検出の閾値

                // PDFパーサーでテキスト抽出（ファイルから直接読み込み）
                $parser = new PdfParser([], $config);
                $pdf = $parser->parseFile($tempFile);
                $text = $pdf->getText();

                // メモリを解放
                unset($pdf);
                unset($parser);

            } catch (\Error $e) {
                $parseError = $e;
                // メモリエラーをキャッチ
                if (str_contains($e->getMessage(), 'memory') || str_contains($e->getMessage(), 'Allowed memory size')) {
                    Log::warning("PDF parsing memory error: {$pdfUrl} - " . $e->getMessage());
                    $text = null;
                    $parseError = new \Exception('PDF parsing requires too much memory');
                }
            } catch (\Exception $e) {
                $parseError = $e;
            }

            // メモリ制限を元に戻す
            ini_set('memory_limit', $originalMemoryLimit);

            // 一時ファイルを削除
            @unlink($tempFile);
            $tempFile = null;

            // パースエラーがあった場合（PDFは保存済みなので，テキスト抽出失敗でもPDFは返す）
            if ($parseError !== null) {
                // PDFが保存できていれば部分的成功
                if ($pdfPath !== null) {
                    Log::info("PDF saved but text extraction failed: {$pdfUrl}");
                    return [
                        'success' => true,
                        'text' => null,
                        'pdf_path' => $pdfPath,
                        'error' => null,
                    ];
                }
                return [
                    'success' => false,
                    'text' => null,
                    'pdf_path' => null,
                    'error' => 'PDF parsing error: ' . $parseError->getMessage(),
                ];
            }

            // テキストが短すぎる場合でもPDFは保存
            if ($text === null || strlen($text) < 500) {
                if ($pdfPath !== null) {
                    Log::info("PDF saved but extracted text too short: {$pdfUrl}");
                    return [
                        'success' => true,
                        'text' => null,
                        'pdf_path' => $pdfPath,
                        'error' => null,
                    ];
                }
                return [
                    'success' => false,
                    'text' => null,
                    'pdf_path' => null,
                    'error' => 'Extracted text too short (possibly scanned PDF or protected)',
                ];
            }

            // クリーンアップ
            $text = $this->cleanExtractedText($text);

            return [
                'success' => true,
                'text' => $text,
                'pdf_path' => $pdfPath,
                'error' => null,
            ];

        } catch (\Exception $e) {
            // 一時ファイルのクリーンアップ
            if ($tempFile !== null && file_exists($tempFile)) {
                @unlink($tempFile);
            }

            return [
                'success' => false,
                'text' => null,
                'pdf_path' => null,
                'error' => 'PDF processing error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * PDFファイルをストレージに保存
     */
    private function savePdfToStorage(string $tempFile, Paper $paper): ?string
    {
        try {
            // ユーザーIDとjournal_idでディレクトリを分ける
            $journal = $paper->journal;
            $userId = $journal ? $journal->user_id : 'unknown';

            // DOIをファイル名に使用（なければpaper ID）
            $fileName = $paper->doi
                ? preg_replace('/[^a-zA-Z0-9._-]/', '_', $paper->doi) . '.pdf'
                : "paper_{$paper->id}.pdf";

            // パス: {user_id}/{journal_id}/{filename}
            $journalId = $paper->journal_id ?? 'unknown';
            $path = "{$userId}/{$journalId}/{$fileName}";

            // ストレージに保存
            $content = file_get_contents($tempFile);
            if ($content === false) {
                Log::warning("Failed to read temp file for storage: {$tempFile}");
                return null;
            }

            Storage::disk('papers')->put($path, $content);
            Log::info("PDF saved to storage: {$path}");

            return $path;

        } catch (\Exception $e) {
            Log::error("Failed to save PDF to storage: " . $e->getMessage());
            return null;
        }
    }

    /**
     * PDFをファイルにストリーミングダウンロード
     */
    private function downloadPdfToFile(string $pdfUrl, string $filePath): array
    {
        try {
            // cURLでストリーミングダウンロード（メモリ効率が良い）
            $ch = curl_init($pdfUrl);
            $fp = fopen($filePath, 'wb');

            if ($fp === false) {
                return [
                    'success' => false,
                    'text' => null,
                    'error' => 'Failed to open temp file for writing',
                ];
            }

            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_TIMEOUT => $this->timeout * 3,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SurveyMate/1.0; Academic Research)',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/pdf, */*',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $error = curl_error($ch);

            curl_close($ch);
            fclose($fp);

            if (!$success || $httpCode >= 400) {
                return [
                    'success' => false,
                    'text' => null,
                    'error' => 'Failed to download PDF: HTTP ' . $httpCode . ($error ? " - {$error}" : ''),
                ];
            }

            // Content-Typeチェック（PDFまたはバイナリ）
            if ($contentType && !str_contains($contentType, 'pdf') && !str_contains($contentType, 'octet-stream') && !str_contains($contentType, 'binary')) {
                // ファイルのマジックバイトでPDFかどうか確認
                $handle = fopen($filePath, 'rb');
                $header = fread($handle, 5);
                fclose($handle);

                if ($header !== '%PDF-') {
                    return [
                        'success' => false,
                        'text' => null,
                        'error' => 'Not a PDF file: ' . $contentType,
                    ];
                }
            }

            return ['success' => true, 'text' => null, 'error' => null];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'text' => null,
                'error' => 'Download error: ' . $e->getMessage(),
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
