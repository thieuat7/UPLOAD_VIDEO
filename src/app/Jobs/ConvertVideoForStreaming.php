<?php

namespace App\Jobs;

use App\Models\Video;
use FFMpeg\Format\Video\X264;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ConvertVideoForStreaming implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $video;

    public $timeout = 3600;

    public function __construct(Video $video)
    {
        $this->video = $video;
    }

    public function handle(): void
    {
        $jobStart = microtime(true);

        $this->video->update([
            'status' => 'processing',
            'processing_started_at' => now(),
        ]);

        Log::info('VIDEO_PROCESSING_STARTED', [
            'video_id' => $this->video->id,
        ]);

        $lowBitrate = (new X264)->setKiloBitrate(250);
        $midBitrate = (new X264)->setKiloBitrate(500);
        $highBitrate = (new X264)->setKiloBitrate(1000);

        $hlsFolder = 'hls/' . $this->video->id;
        $hlsPlaylist = $hlsFolder . '/playlist.m3u8';

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

        $totalSeconds = round(
            microtime(true) - $jobStart,
            2
        );

        $this->video->update([
            'hls_path' => $hlsPlaylist,
            'status' => 'completed',
            'completed_at' => now(),
            'processing_seconds' => $totalSeconds,
        ]);

        Log::info('VIDEO_PROCESSING_COMPLETED', [
            'video_id' => $this->video->id,
            'seconds' => $totalSeconds,
        ]);

        Storage::disk('local')
            ->delete($this->video->original_path);
    }
}