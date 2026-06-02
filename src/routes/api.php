<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\VideoProgressController;

Route::get('/course-videos', function () {
    // 1. Chỉ lấy các video đã convert thành công
    $videos = Video::where('status', 'completed')->oldest()->get();

    // 2. Format dữ liệu trả về cho Frontend
    $lessons = $videos->map(function ($video, $index) {
        return [
            'id' => 'lesson-' . $video->id,
            'title' => ($index + 1) . '. ' . $video->title,
            'type' => 'video',
            // Sử dụng Storage url để tạo link tải m3u8 từ MinIO
            'videoUrl' => Storage::disk('minio')->url($video->hls_path),
            'durationLabel' => 'Video', 
            'completed' => false,
        ];
    });

    // 3. Trả về cấu trúc JSON hoàn chỉnh
    return response()->json([
        'id' => 'mastering-system-architecture',
        'title' => 'Khóa học của tôi',
        'instructor' => [
            'name' => 'Admin',
            'title' => 'Giảng viên',
            'avatarUrl' => 'https://ui-avatars.com/api/?name=Admin',
        ],
        'sections' => [
            [
                'id' => 'section-1',
                'title' => 'Danh sách video từ hệ thống',
                'meta' => $lessons->count() . ' videos',
                'lessons' => $lessons
            ]
        ]
    ]);
});

// Progress tracking demo
Route::get('/video-progress', function () {
    return view('video-progress-demo');
});

// Debug endpoints
Route::get('/debug/redis', function () {
    try {
        \Illuminate\Support\Facades\Redis::ping();
        $info = \Illuminate\Support\Facades\Redis::info();

        return response()->json([
            'redis_connection' => 'OK ✓',
            'info' => $info,
            'test_key' => [
                'set' => \Illuminate\Support\Facades\Redis::set('test_key', 'test_value'),
                'get' => \Illuminate\Support\Facades\Redis::get('test_key'),
                'delete' => \Illuminate\Support\Facades\Redis::del('test_key'),
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'redis_connection' => 'FAILED ✗',
            'error' => $e->getMessage(),
        ], 500);
    }
});

Route::get('/debug/queue', function () {
    $failed = DB::table('failed_jobs')->count();
    $pending = DB::table('jobs')->count();

    return response()->json([
        'queue_status' => 'OK',
        'pending_jobs' => $pending,
        'failed_jobs' => $failed,
    ]);
});

Route::get('/debug/video/{videoId}', function ($videoId) {
    $video = \App\Models\Video::find($videoId);

    if (!$video) {
        return response()->json(['error' => 'Video not found'], 404);
    }

    $tracker = new \App\Services\VideoProgressTracker($videoId);
    $redisData = $tracker->getStatus();

    return response()->json([
        'database' => [
            'id' => $video->id,
            'title' => $video->title,
            'status' => $video->status,
            'original_path' => $video->original_path,
            'hls_path' => $video->hls_path,
            'processing_seconds' => $video->processing_seconds,
            'processing_started_at' => $video->processing_started_at,
            'completed_at' => $video->completed_at,
        ],
        'redis' => $redisData ?: 'No data (not started or expired)',
        'redis_key' => 'video:' . $videoId,
    ]);
});

Route::get('/videos/{videoId}/progress', [VideoProgressController::class, 'getProgress']);