<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Models\Video;

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