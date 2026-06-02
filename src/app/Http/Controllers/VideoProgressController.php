<?php

namespace App\Http\Controllers;

use App\Models\Video;
use App\Services\VideoProgressTracker;
use Illuminate\Http\JsonResponse;

class VideoProgressController extends Controller
{
    public function getProgress(int $videoId): JsonResponse
    {
        $video = Video::find($videoId);

        if (!$video) {
            return response()->json([
                'error' => 'Video not found',
                'video_id' => $videoId,
            ], 404);
        }

        $tracker = new VideoProgressTracker($videoId);
        $redisStatus = $tracker->getStatus();

        return response()->json([
            'video_id' => $videoId,
            'db_status' => $video->status,
            'redis_status' => $redisStatus['status'] ?? null,
            'progress' => (int)($redisStatus['progress'] ?? 0),
            'current_step' => $redisStatus['current_step'] ?? null,
            'error_message' => $redisStatus['error_message'] ?? null,
            'hls_path' => $video->hls_path ?? null,
            'updated_at' => $redisStatus['updated_at'] ?? null,
            'processing_seconds' => $video->processing_seconds ?? null,
            'is_synced' => ($video->status === ($redisStatus['status'] ?? 'unknown')),
        ]);
    }
}
