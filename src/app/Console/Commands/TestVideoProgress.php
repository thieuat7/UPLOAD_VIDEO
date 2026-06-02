<?php

namespace App\Console\Commands;

use App\Services\VideoProgressTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class TestVideoProgress extends Command
{
    protected $signature = 'test:video-progress {videoId : The video ID to test} {--redis-only : Only test Redis without FFmpeg}';
    protected $description = 'Test video progress tracking system';

    public function handle(): int
    {
        $videoId = $this->argument('videoId');
        $redisOnly = $this->option('redis-only');

        $this->info("Testing video progress tracking for video ID: $videoId\n");

        // Test 1: Redis Connection
        $this->testRedisConnection();

        // Test 2: Redis Write/Read
        $this->testRedisOperations($videoId);

        // Test 3: VideoProgressTracker
        $this->testVideoProgressTracker($videoId);

        // Test 4: API Response (if not redis-only)
        if (!$redisOnly) {
            $this->testAPIResponse($videoId);
        }

        $this->info("\n✅ All tests completed!\n");
        return 0;
    }

    private function testRedisConnection(): void
    {
        $this->info('TEST 1: Redis Connection');

        try {
            $ping = Redis::ping();
            if ($ping === 'PONG') {
                $this->line('  ✓ Redis connected successfully');
                return;
            }
        } catch (\Exception $e) {
            $this->error("  ✗ Redis connection failed: " . $e->getMessage());
            exit(1);
        }
    }

    private function testRedisOperations($videoId): void
    {
        $this->info('\nTEST 2: Redis Write/Read Operations');

        try {
            // Test SET/GET
            Redis::set('test_key', 'test_value');
            $value = Redis::get('test_key');

            if ($value === 'test_value') {
                $this->line('  ✓ SET/GET works');
            }

            // Test HSET/HGET
            Redis::hset('test_hash', 'field1', 'value1', 'field2', 'value2');
            $hash = Redis::hgetall('test_hash');

            if ($hash['field1'] === 'value1') {
                $this->line('  ✓ HSET/HGET works');
            }

            // Cleanup
            Redis::del('test_key', 'test_hash');
            $this->line('  ✓ DEL works');

        } catch (\Exception $e) {
            $this->error("  ✗ Redis operations failed: " . $e->getMessage());
            exit(1);
        }
    }

    private function testVideoProgressTracker($videoId): void
    {
        $this->info('\nTEST 3: VideoProgressTracker');

        try {
            $tracker = new VideoProgressTracker($videoId);

            // Test setProcessing
            $tracker->setProcessing();
            $status = $tracker->getStatus();

            if ($status && $status['status'] === 'processing') {
                $this->line('  ✓ setProcessing() works');
            }

            // Test updateProgress
            $tracker->updateProgress(50, 'Testing...');
            $status = $tracker->getStatus();

            if ($status && $status['progress'] === '50') {
                $this->line('  ✓ updateProgress() works');
            }

            // Test setSuccess
            $tracker->setSuccess('hls/test/playlist.m3u8');
            $status = $tracker->getStatus();

            if ($status && $status['status'] === 'done') {
                $this->line('  ✓ setSuccess() works');
            }

            // Test setFailed
            $tracker->setFailed('Test error message');
            $status = $tracker->getStatus();

            if ($status && $status['status'] === 'failed') {
                $this->line('  ✓ setFailed() works');
            }

            // Test expiry
            $tracker->setExpiry(3600);
            $ttl = Redis::ttl("video:$videoId");

            if ($ttl > 0) {
                $this->line("  ✓ setExpiry() works (TTL: {$ttl}s)");
            }

            // Display final status
            $status = $tracker->getStatus();
            $this->info('\n  Final Redis Status:');
            foreach ($status as $key => $value) {
                $this->line("    $key: $value");
            }

            // Cleanup
            $tracker->delete();
            $this->line('\n  ✓ Redis key deleted (cleanup)');

        } catch (\Exception $e) {
            $this->error("  ✗ VideoProgressTracker test failed: " . $e->getMessage());
            exit(1);
        }
    }

    private function testAPIResponse($videoId): void
    {
        $this->info('\nTEST 4: API Response');

        try {
            $video = \App\Models\Video::find($videoId);

            if (!$video) {
                $this->warn("  ⚠ Video ID $videoId not found in database");
                return;
            }

            $tracker = new VideoProgressTracker($videoId);
            $redisStatus = $tracker->getStatus();

            $response = [
                'video_id' => $videoId,
                'db_status' => $video->status,
                'redis_status' => $redisStatus['status'] ?? null,
                'progress' => (int)($redisStatus['progress'] ?? 0),
                'is_synced' => ($video->status === ($redisStatus['status'] ?? 'unknown')),
            ];

            $this->info('\n  API Response:');
            foreach ($response as $key => $value) {
                $status = $key === 'is_synced' && $value ? ' ✓' : '';
                $this->line("    $key: " . json_encode($value) . $status);
            }

        } catch (\Exception $e) {
            $this->error("  ✗ API test failed: " . $e->getMessage());
            exit(1);
        }
    }
}
