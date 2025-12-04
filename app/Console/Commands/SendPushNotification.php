<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Paper;
use App\Services\WebPushService;
use Carbon\Carbon;

class SendPushNotification extends Command
{
    protected $signature = 'push:send
                            {--test : Send a test notification}';

    protected $description = 'Send push notifications for new papers';

    /** @var WebPushService */
    private $pushService;

    public function __construct(WebPushService $pushService)
    {
        parent::__construct();
        $this->pushService = $pushService;
    }

    public function handle(): int
    {
        if (!$this->pushService->isConfigured()) {
            $this->warn('Push notifications are not configured (VAPID keys missing)');
            return Command::SUCCESS;
        }

        if ($this->option('test')) {
            return $this->sendTestNotification();
        }

        return $this->sendDailyNotification();
    }

    private function sendTestNotification(): int
    {
        $this->info('Sending test notification...');

        $payload = [
            'title' => 'AutoSurvey テスト通知',
            'body' => 'プッシュ通知が正常に設定されています！',
            'icon' => '/icon-192.png',
            'badge' => '/icon-192.png',
            'tag' => 'test-' . time(),
            'data' => [
                'type' => 'test',
                'url' => '/',
            ],
        ];

        $results = $this->pushService->sendToAll($payload);

        $this->info("Notifications sent: {$results['success']} success, {$results['failed']} failed");

        return Command::SUCCESS;
    }

    private function sendDailyNotification(): int
    {
        // Get papers from the last 24 hours
        $since = Carbon::now()->subDay();
        $newPapersCount = Paper::where('published_at', '>=', $since)->count();

        if ($newPapersCount === 0) {
            $this->info('No new papers to notify about.');
            return Command::SUCCESS;
        }

        $this->info("Sending notification for {$newPapersCount} new papers...");

        // Get sample paper titles for the notification
        $samplePapers = Paper::where('published_at', '>=', $since)
            ->orderBy('published_at', 'desc')
            ->limit(3)
            ->get();

        $bodyText = $newPapersCount === 1
            ? $samplePapers->first()->title
            : "{$newPapersCount}件の新着論文があります";

        $payload = [
            'title' => 'AutoSurvey 新着論文',
            'body' => mb_strlen($bodyText) > 100 ? mb_substr($bodyText, 0, 97) . '...' : $bodyText,
            'icon' => '/icon-192.png',
            'badge' => '/icon-192.png',
            'tag' => 'daily-' . date('Y-m-d'),
            'data' => [
                'type' => 'new_papers',
                'url' => '/',
                'count' => $newPapersCount,
            ],
        ];

        $results = $this->pushService->sendToAll($payload);

        $this->info("Notifications sent: {$results['success']} success, {$results['failed']} failed");

        return Command::SUCCESS;
    }
}
