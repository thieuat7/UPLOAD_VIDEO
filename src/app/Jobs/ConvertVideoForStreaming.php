<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\VideoProgressTracker;
use FFMpeg\Format\Video\X264;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConvertVideoForStreaming implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $video;
    public $timeout = 3600;
    private VideoProgressTracker $progressTracker;

    public function __construct(Video $video)
    {
        $this->video = $video;
    }

    public function handle(): void
    {
        $jobStart = microtime(true);
        $this->progressTracker = new VideoProgressTracker($this->video->id);

        try {
            // 0% - Initialize
            $this->video->update([
                'status' => 'processing',
                'processing_started_at' => now(),
            ]);
            $this->progressTracker->setProcessing();

            Log::info('VIDEO_PROCESSING_STARTED', [
                'video_id' => $this->video->id,
            ]);

            // 10% - Setup phase complete
            $this->progressTracker->updateProgress(10, 'Chuẩn bị định dạng video');

            $lowBitrate = (new X264)->setKiloBitrate(250);
            $midBitrate = (new X264)->setKiloBitrate(500);
            $highBitrate = (new X264)->setKiloBitrate(1000);

            $hlsFolder = 'hls/' . $this->video->id;
            $hlsPlaylist = $hlsFolder . '/playlist.m3u8';

            // 30% - FFmpeg conversion started
            $this->progressTracker->updateProgress(30, 'Đang chuyển đổi video HLS');

            FFMpeg::fromDisk('local')
                ->open($this->video->original_path)
                ->exportForHLS()
                ->toDisk('minio')
                ->addFormat($lowBitrate, function ($media) {
                    $media->addFilter('scale=-2:360');
                })
                ->addFormat($midBitrate, function ($media) {
                    $media->addFilter('scale=-2:480');
                })
                ->addFormat($highBitrate, function ($media) {
                    $media->addFilter('scale=-2:720');
                })
                ->save($hlsPlaylist);

            // 70% - Conversion complete, cleanup starting
            $this->progressTracker->updateProgress(70, 'Hoàn tất chuyển đổi, đang dọn dẹp');

            $totalSeconds = round(microtime(true) - $jobStart, 2);

            // 100% - Success
            $this->video->update([
                'hls_path' => $hlsPlaylist,
                'status' => 'completed',
                'completed_at' => now(),
                'processing_seconds' => $totalSeconds,
            ]);

            $this->progressTracker->setSuccess($hlsPlaylist);

            Log::info('VIDEO_PROCESSING_COMPLETED', [
                'video_id' => $this->video->id,
                'seconds' => $totalSeconds,
            ]);

            Storage::disk('local')->delete($this->video->original_path);
            $this->progressTracker->setExpiry(86400);

        } catch (Throwable $e) {
            $totalSeconds = round(microtime(true) - $jobStart, 2);

            $this->video->update([
                'status' => 'failed',
                'processing_seconds' => $totalSeconds,
            ]);

            $this->progressTracker->setFailed($e->getMessage());

            Log::error('VIDEO_PROCESSING_FAILED', [
                'video_id' => $this->video->id,
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->progressTracker->setExpiry(86400);
            throw $e;
        }
    }
}
