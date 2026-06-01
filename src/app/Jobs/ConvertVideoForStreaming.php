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
        // 1. Cập nhật trạng thái đang xử lý
        $this->video->update(['status' => 'processing']);

        // 2. Cấu hình độ phân giải và bitrate cho video đầu ra (chuẩn X264)
        $lowBitrate  = (new X264)->setKiloBitrate(250);
        $midBitrate  = (new X264)->setKiloBitrate(500);
        $highBitrate = (new X264)->setKiloBitrate(1000);

        // Định nghĩa thư mục lưu trữ trên MinIO dựa theo ID của video
        $hlsFolder = 'hls/' . $this->video->id;
        $hlsPlaylist = $hlsFolder . '/playlist.m3u8';

        // 3. Tiến hành băm video gốc sang chuẩn HLS truyền phát trực tuyến
        FFMpeg::fromDisk('local')
            ->open($this->video->original_path)
            ->exportForHLS()
            ->toDisk('minio')
            ->addFormat($lowBitrate)
            ->addFormat($midBitrate)
            ->addFormat($highBitrate)
            ->save($hlsPlaylist);

        // 4. Cập nhật đường dẫn file m3u8 và chuyển trạng thái hoàn thành
        $this->video->update([
            'hls_path' => $hlsPlaylist,
            'status' => 'completed'
        ]);

        // 5. Xóa file video gốc ở thư mục tạm trên local để tránh đầy ổ cứng
        Storage::disk('local')->delete($this->video->original_path);
    }
}
