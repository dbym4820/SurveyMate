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
    private const CROSSREF_API_URL = 'https://api.crossref.org/works/';
    private const DOI_RESOLVER_URL = 'https://doi.org/';

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

        // 1. Unpaywall APIからすべてのOAロケーションを取得し，優先度順に試行
        $unpaywallResult = $this->fetchFromUnpaywall($paper);
        if ($unpaywallResult['success']) {
            return $unpaywallResult;
        }

        // 2. DOI直接解決（doi.org経由）
        $doiResult = $this->fetchFromDoiResolver($paper);
        if ($doiResult['success']) {
            return $doiResult;
        }

        // 3. CrossRef APIからリンクを取得
        $crossrefResult = $this->fetchFromCrossRef($paper);
        if ($crossrefResult['success']) {
            return $crossrefResult;
        }

        // 4. フォールバック: 論文URLからHTML本文抽出
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
     * Unpaywall APIからPDF/本文を取得（すべてのOAロケーションを優先度順に試行）
     */
    private function fetchFromUnpaywall(Paper $paper): array
    {
        if (empty($this->unpaywallEmail)) {
            Log::warning('Unpaywall email not configured');
            return $this->failureResult('Unpaywall email not configured');
        }

        try {
            // DOIはパスの一部として使用するため，スラッシュはエンコードしない
            // 他の特殊文字（スペース，#など）のみエンコード
            $cleanDoi = trim($paper->doi);
            $url = self::UNPAYWALL_API_URL . $cleanDoi . '?email=' . urlencode($this->unpaywallEmail);

            Log::info("Unpaywall API request: {$url}");

            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'User-Agent' => 'SurveyMate/1.0 (mailto:' . $this->unpaywallEmail . ')',
                ])
                ->get($url);

            if (!$response->successful()) {
                Log::info("Unpaywall API: No data for DOI {$paper->doi} (HTTP {$response->status()})");
                return $this->failureResult('Unpaywall API error: ' . $response->status());
            }

            $data = $response->json();

            // OAロケーションを優先度でソート
            $locations = $this->sortOaLocations($data);

            if (empty($locations)) {
                Log::info("Unpaywall: No OA locations for DOI {$paper->doi}");
                return $this->failureResult('No OA locations available');
            }

            Log::info("Unpaywall: Found " . count($locations) . " OA locations for DOI {$paper->doi}");

            // 各ロケーションを順番に試行
            foreach ($locations as $location) {
                $result = $this->tryOaLocation($location, $paper);
                if ($result['success']) {
                    return $result;
                }
            }

            return $this->failureResult('All Unpaywall locations failed');

        } catch (\Exception $e) {
            Log::error("Unpaywall API exception for DOI {$paper->doi}: " . $e->getMessage());
            return $this->failureResult('Unpaywall exception: ' . $e->getMessage());
        }
    }

    /**
     * OAロケーションを優先度でソート
     * 優先順位: publisher > repository > other
     * 同じタイプ内では is_best を優先
     */
    private function sortOaLocations(array $data): array
    {
        $locations = [];

        // best_oa_locationを最初に追加
        if (!empty($data['best_oa_location'])) {
            $best = $data['best_oa_location'];
            $best['_is_best'] = true;
            $locations[] = $best;
        }

        // oa_locationsからbestでないものを追加
        $oaLocations = $data['oa_locations'] ?? [];
        foreach ($oaLocations as $loc) {
            // best_oa_locationと重複しないかチェック
            $isDuplicate = false;
            foreach ($locations as $existing) {
                if (($existing['url_for_pdf'] ?? '') === ($loc['url_for_pdf'] ?? '') &&
                    ($existing['url_for_landing_page'] ?? '') === ($loc['url_for_landing_page'] ?? '')) {
                    $isDuplicate = true;
                    break;
                }
            }
            if (!$isDuplicate) {
                $loc['_is_best'] = false;
                $locations[] = $loc;
            }
        }

        // 優先度でソート
        usort($locations, function ($a, $b) {
            // is_bestを優先
            if (($a['_is_best'] ?? false) && !($b['_is_best'] ?? false)) return -1;
            if (!($a['_is_best'] ?? false) && ($b['_is_best'] ?? false)) return 1;

            // host_typeで優先度付け
            $typePriority = ['publisher' => 0, 'repository' => 1];
            $aType = $typePriority[$a['host_type'] ?? ''] ?? 2;
            $bType = $typePriority[$b['host_type'] ?? ''] ?? 2;

            if ($aType !== $bType) {
                return $aType - $bType;
            }

            // PDF URLがあるものを優先
            $aHasPdf = !empty($a['url_for_pdf']);
            $bHasPdf = !empty($b['url_for_pdf']);
            if ($aHasPdf && !$bHasPdf) return -1;
            if (!$aHasPdf && $bHasPdf) return 1;

            return 0;
        });

        return $locations;
    }

    /**
     * 単一のOAロケーションからPDF/本文取得を試行
     */
    private function tryOaLocation(array $location, Paper $paper): array
    {
        $hostType = $location['host_type'] ?? 'unknown';
        $pdfUrl = $location['url_for_pdf'] ?? null;
        $landingUrl = $location['url_for_landing_page'] ?? null;

        Log::info("Trying OA location: type={$hostType}, pdf=" . ($pdfUrl ? 'yes' : 'no') . ", landing=" . ($landingUrl ? 'yes' : 'no'));

        // 1. PDF URLがあればPDFダウンロードを試行
        if ($pdfUrl) {
            $result = $this->extractTextFromPdf($pdfUrl, $paper);
            if ($result['success']) {
                return [
                    'success' => true,
                    'source' => "unpaywall_{$hostType}_pdf",
                    'text' => $this->truncateText($result['text'] ?? ''),
                    'pdf_url' => $pdfUrl,
                    'pdf_path' => $result['pdf_path'] ?? null,
                    'error' => null,
                ];
            }
            Log::info("PDF extraction failed for {$pdfUrl}: " . ($result['error'] ?? 'unknown'));
        }

        // 2. ランディングページからPDFリンクや本文を抽出
        if ($landingUrl) {
            // まずPDFリンクを探す
            $pdfFromLanding = $this->findPdfLinkFromLandingPage($landingUrl);
            if ($pdfFromLanding) {
                Log::info("Found PDF link from landing page: {$pdfFromLanding}");
                $result = $this->extractTextFromPdf($pdfFromLanding, $paper);
                if ($result['success']) {
                    return [
                        'success' => true,
                        'source' => "unpaywall_{$hostType}_landing_pdf",
                        'text' => $this->truncateText($result['text'] ?? ''),
                        'pdf_url' => $pdfFromLanding,
                        'pdf_path' => $result['pdf_path'] ?? null,
                        'error' => null,
                    ];
                }
            }

            // HTML本文抽出を試行
            $htmlResult = $this->extractTextFromHtml($landingUrl);
            if ($htmlResult['success']) {
                return [
                    'success' => true,
                    'source' => "unpaywall_{$hostType}_html",
                    'text' => $this->truncateText($htmlResult['text']),
                    'pdf_url' => null,
                    'pdf_path' => null,
                    'error' => null,
                ];
            }
        }

        return $this->failureResult('OA location extraction failed');
    }

    /**
     * ランディングページからPDFリンクを探す
     */
    private function findPdfLinkFromLandingPage(string $url): ?string
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; SurveyMate/1.0; Academic Research)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);

            if (!$response->successful()) {
                return null;
            }

            $html = $response->body();
            $baseUrl = $this->getBaseUrl($url);

            // PDFリンクのパターンを探す
            $patterns = [
                // 直接的なPDFリンク
                '/<a[^>]+href=["\']([^"\']*\.pdf(?:\?[^"\']*)?)["\'][^>]*>/i',
                // data-pdf-url属性
                '/<[^>]+data-pdf-url=["\']([^"\']+)["\'][^>]*>/i',
                // PDF downloadリンク
                '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>\s*(?:Download\s+)?PDF/i',
                '/<a[^>]+href=["\']([^"\']+)["\'][^>]*class="[^"]*pdf[^"]*"[^>]*>/i',
                // content-urlメタタグ
                '/<meta[^>]+name=["\']citation_pdf_url["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i',
                // リンクタグ
                '/<link[^>]+rel=["\']alternate["\'][^>]+type=["\']application\/pdf["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $html, $matches)) {
                    foreach ($matches[1] as $match) {
                        $pdfUrl = $this->resolveUrl($match, $baseUrl);
                        // PDFかどうか簡易チェック
                        if ($this->isPotentialPdfUrl($pdfUrl)) {
                            return $pdfUrl;
                        }
                    }
                }
            }

            return null;

        } catch (\Exception $e) {
            Log::warning("Failed to find PDF link from {$url}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * URLがPDFの可能性があるかチェック
     */
    private function isPotentialPdfUrl(string $url): bool
    {
        $lower = strtolower($url);
        return str_contains($lower, '.pdf') ||
               str_contains($lower, '/pdf/') ||
               str_contains($lower, 'pdf?') ||
               str_contains($lower, 'type=pdf') ||
               str_contains($lower, 'format=pdf');
    }

    /**
     * DOI直接解決でリダイレクト先からPDF/本文取得
     */
    private function fetchFromDoiResolver(Paper $paper): array
    {
        try {
            $doiUrl = self::DOI_RESOLVER_URL . $paper->doi;

            // リダイレクトを追跡して最終URLを取得
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; SurveyMate/1.0; Academic Research)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml',
                ])
                ->withOptions(['allow_redirects' => ['track_redirects' => true]])
                ->get($doiUrl);

            if (!$response->successful()) {
                return $this->failureResult('DOI resolver failed: ' . $response->status());
            }

            // 最終的なURLを取得
            $finalUrl = $response->effectiveUri()?->__toString() ?? $doiUrl;
            Log::info("DOI {$paper->doi} resolved to: {$finalUrl}");

            // 最終ページからPDFリンクを探す
            $pdfUrl = $this->findPdfLinkFromLandingPage($finalUrl);
            if ($pdfUrl) {
                $result = $this->extractTextFromPdf($pdfUrl, $paper);
                if ($result['success']) {
                    return [
                        'success' => true,
                        'source' => 'doi_resolver_pdf',
                        'text' => $this->truncateText($result['text'] ?? ''),
                        'pdf_url' => $pdfUrl,
                        'pdf_path' => $result['pdf_path'] ?? null,
                        'error' => null,
                    ];
                }
            }

            // HTML本文抽出を試行
            $htmlResult = $this->extractTextFromHtml($finalUrl);
            if ($htmlResult['success']) {
                return [
                    'success' => true,
                    'source' => 'doi_resolver_html',
                    'text' => $this->truncateText($htmlResult['text']),
                    'pdf_url' => null,
                    'pdf_path' => null,
                    'error' => null,
                ];
            }

            return $this->failureResult('DOI resolver: no content extracted');

        } catch (\Exception $e) {
            Log::warning("DOI resolver exception for {$paper->doi}: " . $e->getMessage());
            return $this->failureResult('DOI resolver exception: ' . $e->getMessage());
        }
    }

    /**
     * CrossRef APIからリンクを取得
     */
    private function fetchFromCrossRef(Paper $paper): array
    {
        try {
            // DOIはパスの一部として使用するため，スラッシュはエンコードしない
            $cleanDoi = trim($paper->doi);
            $url = self::CROSSREF_API_URL . $cleanDoi;

            Log::info("CrossRef API request: {$url}");

            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'User-Agent' => 'SurveyMate/1.0 (mailto:' . ($this->unpaywallEmail ?? 'research@example.com') . ')',
                ])
                ->get($url);

            if (!$response->successful()) {
                return $this->failureResult('CrossRef API error: ' . $response->status());
            }

            $data = $response->json();
            $message = $data['message'] ?? [];

            // リンクを収集
            $links = [];

            // link配列から
            foreach ($message['link'] ?? [] as $link) {
                if (!empty($link['URL'])) {
                    $links[] = [
                        'url' => $link['URL'],
                        'content_type' => $link['content-type'] ?? '',
                        'intended_application' => $link['intended-application'] ?? '',
                    ];
                }
            }

            // resourceから
            if (!empty($message['resource']['primary']['URL'])) {
                $links[] = [
                    'url' => $message['resource']['primary']['URL'],
                    'content_type' => '',
                    'intended_application' => 'primary',
                ];
            }

            if (empty($links)) {
                return $this->failureResult('CrossRef: no links found');
            }

            Log::info("CrossRef: Found " . count($links) . " links for DOI {$paper->doi}");

            // PDFリンクを優先
            usort($links, function ($a, $b) {
                $aIsPdf = str_contains($a['content_type'], 'pdf') || str_contains($a['url'], '.pdf');
                $bIsPdf = str_contains($b['content_type'], 'pdf') || str_contains($b['url'], '.pdf');
                if ($aIsPdf && !$bIsPdf) return -1;
                if (!$aIsPdf && $bIsPdf) return 1;
                return 0;
            });

            foreach ($links as $link) {
                $linkUrl = $link['url'];
                $isPdf = str_contains($link['content_type'], 'pdf') || $this->isPotentialPdfUrl($linkUrl);

                if ($isPdf) {
                    $result = $this->extractTextFromPdf($linkUrl, $paper);
                    if ($result['success']) {
                        return [
                            'success' => true,
                            'source' => 'crossref_pdf',
                            'text' => $this->truncateText($result['text'] ?? ''),
                            'pdf_url' => $linkUrl,
                            'pdf_path' => $result['pdf_path'] ?? null,
                            'error' => null,
                        ];
                    }
                }

                // ランディングページとして処理
                $pdfFromLanding = $this->findPdfLinkFromLandingPage($linkUrl);
                if ($pdfFromLanding) {
                    $result = $this->extractTextFromPdf($pdfFromLanding, $paper);
                    if ($result['success']) {
                        return [
                            'success' => true,
                            'source' => 'crossref_landing_pdf',
                            'text' => $this->truncateText($result['text'] ?? ''),
                            'pdf_url' => $pdfFromLanding,
                            'pdf_path' => $result['pdf_path'] ?? null,
                            'error' => null,
                        ];
                    }
                }

                // HTML抽出
                $htmlResult = $this->extractTextFromHtml($linkUrl);
                if ($htmlResult['success']) {
                    return [
                        'success' => true,
                        'source' => 'crossref_html',
                        'text' => $this->truncateText($htmlResult['text']),
                        'pdf_url' => null,
                        'pdf_path' => null,
                        'error' => null,
                    ];
                }
            }

            return $this->failureResult('CrossRef: all links failed');

        } catch (\Exception $e) {
            Log::warning("CrossRef API exception for {$paper->doi}: " . $e->getMessage());
            return $this->failureResult('CrossRef exception: ' . $e->getMessage());
        }
    }

    /**
     * 失敗結果を生成
     */
    private function failureResult(string $error): array
    {
        return [
            'success' => false,
            'source' => null,
            'text' => null,
            'pdf_url' => null,
            'pdf_path' => null,
            'error' => $error,
        ];
    }

    /**
     * URLのベース部分を取得
     */
    private function getBaseUrl(string $url): string
    {
        $parsed = parse_url($url);
        return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
    }

    /**
     * 相対URLを絶対URLに解決
     */
    private function resolveUrl(string $url, string $baseUrl): string
    {
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, '/')) {
            return $baseUrl . $url;
        }
        return $baseUrl . '/' . $url;
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
            '/<div[^>]*class="[^"]*(?:article-body|full-text|paper-content|main-content|c-article-body|article__body|fulltext)[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<section[^>]*class="[^"]*(?:article-section|body|content)[^"]*"[^>]*>(.*?)<\/section>/is',
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
