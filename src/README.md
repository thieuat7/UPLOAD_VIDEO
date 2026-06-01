# Hướng Dẫn Dự Án Tải Lên Video HLS lên MinIO

## Cấu trúc mã nguồn chính liên quan trong thư mục này:

1.  **Cấu hình đĩa lưu trữ (MinIO / Local)**:
    *   [src/config/filesystems.php](filesystems.php) (cấu hình disk `minio` kết nối S3 API)
2.  **Model lưu thông tin Video**:
    *   [src/app/Models/Video.php](app/Models/Video.php) (chứa các trường `original_path`, `hls_path`, `status`, `processing_seconds`,...)
3.  **Giao diện Quản trị Filament**:
    *   [src/app/Filament/Resources/VideoResource.php](app/Filament/Resources/VideoResource.php) (quản lý form upload, bảng danh sách và modal phát video HLS)
    *   [src/app/Filament/Resources/VideoResource/Pages/CreateVideo.php](app/Filament/Resources/VideoResource/Pages/CreateVideo.php) (kích hoạt hàng đợi job sau khi upload)
4.  **Job xử lý chuyển đổi HLS**:
    *   [src/app/Jobs/ConvertVideoForStreaming.php](app/Jobs/ConvertVideoForStreaming.php) (sử dụng FFmpeg để chuyển đổi video thành HLS đa bitrate 360p, 480p, 720p và lưu lên MinIO)
5.  **Trình phát video**:
    *   [src/resources/views/video-player.blade.php](resources/views/video-player.blade.php) (sử dụng HLS.js để phát luồng thích ứng từ MinIO công khai)
