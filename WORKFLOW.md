# 📹 Luồng Hoạt Động Tạo Video & Streaming

## 1️⃣ **PHASE 1: Upload Video (Bước 1-3)**

```
User Interface (Filament Admin)
        ↓
[Form nhập Title + Chọn file MP4]
        ↓
VideoResource::form() (VideoResource.php)
  • TextInput: title (bắt buộc, max 255 ký tự)
  • FileUpload: original_path
    - Disk: 'local' (lưu server)
    - Directory: 'temp_videos/'
    - Accept: video/mp4 only
        ↓
User nhấn "Tạo"
        ↓
Filament::CreateRecord triggered
        ↓
```

**Database trước khi xử lý:**
```sql
videos table:
┌────┬──────────────┬──────────────────────────┬──────────┬─────────────┐
│ id │ title        │ original_path            │ status   │ created_at  │
├────┼──────────────┼──────────────────────────┼──────────┼─────────────┤
│ 1  │ Học PHP 101  │ temp_videos/abc123.mp4   │ pending  │ 2026-06-02  │
└────┴──────────────┴──────────────────────────┴──────────┴─────────────┘
  • hls_path: NULL
  • processing_started_at: NULL
  • completed_at: NULL
  • processing_seconds: NULL
```

---

## 2️⃣ **PHASE 2: Queue Job Dispatch (Bước 4)**

```
CreateVideo::afterCreate() triggered
        ↓
ConvertVideoForStreaming::dispatch($this->record)
        ↓
Job được push vào Queue (jobs table)
```

**Jobs Table:**
```sql
┌───┬────────┬──────────────────────────────┬────────┐
│ id│ queue  │ payload                      │ status │
├───┼────────┼──────────────────────────────┼────────┤
│ 1 │ default│ ConvertVideoForStreaming ... │ pending│
└───┴────────┴──────────────────────────────┴────────┘
```

**Redis (Progress Tracking):**
```
video:1 (Hash)
┌─────────────────┬────────────────┐
│ status          │ processing     │
│ progress        │ 0              │
│ updated_at      │ 2026-06-02 10:00:00 │
└─────────────────┴────────────────┘
```

---

## 3️⃣ **PHASE 3: Job Processing (Bước 5-10)**

```
Queue Worker bắt đầu chạy Job
        ↓
ConvertVideoForStreaming::handle()
        ↓
TRY BLOCK STARTS
        ↓
┌─────────────────────────────────────────────────────────────┐
│ [0%] Khởi tạo                                               │
├─────────────────────────────────────────────────────────────┤
│  • Update DB: status = 'processing'                          │
│  • Set processing_started_at = NOW()                         │
│  • Log: VIDEO_PROCESSING_STARTED                             │
│  • Redis: video:1 → status: processing, progress: 0         │
└─────────────────────────────────────────────────────────────┘
        ↓
┌─────────────────────────────────────────────────────────────┐
│ [10%] Chuẩn bị định dạng video                              │
├─────────────────────────────────────────────────────────────┤
│  • Setup 3 bitrate formats:                                  │
│    - Low (250kbps, 360p)                                     │
│    - Mid (500kbps, 480p)                                     │
│    - High (1000kbps, 720p)                                   │
│  • Redis: progress = 10, current_step = "Chuẩn bị..."       │
└─────────────────────────────────────────────────────────────┘
        ↓
┌─────────────────────────────────────────────────────────────┐
│ [30%] Đang chuyển đổi video HLS                             │
├─────────────────────────────────────────────────────────────┤
│  • FFMpeg::fromDisk('local')                                 │
│    .open('temp_videos/abc123.mp4')                           │
│    .exportForHLS()                                            │
│    .toDisk('minio')                                           │
│    .addFormat(lowBitrate) → scale=-2:360                    │
│    .addFormat(midBitrate) → scale=-2:480                    │
│    .addFormat(highBitrate) → scale=-2:720                   │
│    .save('hls/1/playlist.m3u8')                             │
│                                                              │
│  Quá trình:                                                  │
│  1. Đọc file MP4 từ local storage                            │
│  2. Chạy FFmpeg encode thành 3 variants HLS                 │
│  3. Tạo segment files (.ts):                                │
│     hls/1/stream-360p-0.ts                                  │
│     hls/1/stream-360p-1.ts ...                              │
│     hls/1/stream-480p-0.ts ...                              │
│     hls/1/stream-720p-0.ts ...                              │
│  4. Tạo playlist file:                                      │
│     hls/1/stream-360p.m3u8                                  │
│     hls/1/stream-480p.m3u8                                  │
│     hls/1/stream-720p.m3u8                                  │
│     hls/1/playlist.m3u8 (master playlist)                   │
│                                                              │
│  ⏱️ Thời gian: phụ thuộc vào độ dài video                    │
│     - Video 5 phút: ~20-30 giây                              │
│     - Video 1 giờ: ~3-5 phút                                 │
│  • Redis: progress = 30, current_step = "Đang chuyển..."   │
└─────────────────────────────────────────────────────────────┘
        ↓
┌─────────────────────────────────────────────────────────────┐
│ [70%] Hoàn tất chuyển đổi, đang dọn dẹp                     │
├─────────────────────────────────────────────────────────────┤
│  Tính toán tổng thời gian xử lý                              │
│  Redis: progress = 70, current_step = "Hoàn tất..."        │
└─────────────────────────────────────────────────────────────┘
        ↓
┌─────────────────────────────────────────────────────────────┐
│ [100%] THÀNH CÔNG ✅                                         │
├─────────────────────────────────────────────────────────────┤
│  • Update DB:                                                 │
│    - status = 'completed'                                    │
│    - hls_path = 'hls/1/playlist.m3u8'                       │
│    - completed_at = NOW()                                    │
│    - processing_seconds = 25.50                              │
│  • Log: VIDEO_PROCESSING_COMPLETED                           │
│  • Delete original file: temp_videos/abc123.mp4              │
│    (Giải phóng dung lượng)                                   │
│  • Redis: status = 'done', progress = 100, hls_path = ... │
│    + expire sau 24 giờ (tự động xóa)                        │
└─────────────────────────────────────────────────────────────┘
        ↓
END TRY BLOCK
```

**Nếu xảy ra LỖI ❌ trong bất kỳ bước nào:**

```
CATCH BLOCK TRIGGERED
        ↓
┌─────────────────────────────────────────────────────────────┐
│ [ERROR HANDLING]                                             │
├─────────────────────────────────────────────────────────────┤
│  • Tính toán thời gian xử lý đến lúc lỗi                     │
│  • Update DB:                                                 │
│    - status = 'failed'                                       │
│    - processing_seconds = 12.30 (lỗi ở giây 12)             │
│  • Log: VIDEO_PROCESSING_FAILED                              │
│    - video_id, error_message, file, line                    │
│  • Redis:                                                     │
│    - status = 'failed'                                       │
│    - error_message = "Exception message"                     │
│    - expire sau 24 giờ                                       │
│  • Throw exception lại (Queue biết job thất bại)            │
└─────────────────────────────────────────────────────────────┘
```

---

## 4️⃣ **PHASE 4: Monitoring & Tracking Progress (Real-time)**

**Frontend có thể polling API:**
```
GET /api/videos/{videoId}/progress
```

**Response (status processing):**
```json
{
  "video_id": 1,
  "status": "processing",
  "progress": 30,
  "current_step": "Đang chuyển đổi video HLS",
  "error_message": null,
  "hls_path": null,
  "updated_at": "2026-06-02 10:00:15"
}
```

**Response (status done):**
```json
{
  "video_id": 1,
  "status": "done",
  "progress": 100,
  "current_step": null,
  "error_message": null,
  "hls_path": "hls/1/playlist.m3u8",
  "updated_at": "2026-06-02 10:00:45"
}
```

---

## 5️⃣ **PHASE 5: View & Playback (Bước 11-13)**

```
User vào trang List Videos
        ↓
VideoResource::table() hiển thị danh sách
        ↓
Nếu status = 'completed':
  • Hiển thị nút "Xem Video" (play_circle icon)
  • Nếu hls_path đã fill
        ↓
User nhấn "Xem Video"
        ↓
Filament Modal mở:
  • Tiêu đề: "Đang phát: Học PHP 101"
  • Nút đóng: "Đóng"
  • Content: video-player view
        ↓
video-player view nhận:
  • videoUrl = Storage::disk('minio')->url('hls/1/playlist.m3u8')
    ↓ Convert thành public URL từ MinIO
    ↓ VD: https://minio.example.com/hls/1/playlist.m3u8
  • recordId = 1
        ↓
HLS Player (HLS.js / Video.js) phát video:
  1. Load master playlist (hls/1/playlist.m3u8)
  2. Parse danh sách variants (360p, 480p, 720p)
  3. Tự động chọn bitrate phù hợp dựa vào:
     - Network speed
     - Device capability
  4. Download & phát các segment
  5. Adaptive bitrate streaming (ABR)
     - Nếu network tốt → phát 720p
     - Nếu network yếu → downgrade xuống 480p hoặc 360p
        ↓
Video được phát ✅
```

---

## 📊 **Database State Changes - Toàn bộ luồng**

### Step 1: User nhấn "Tạo"
```sql
INSERT INTO videos (title, original_path, status, created_at, updated_at)
VALUES ('Học PHP 101', 'temp_videos/abc123.mp4', 'pending', NOW(), NOW());
```

### Step 2-4: Sau khi dispatch job
```sql
-- Vẫn pending, nhưng job đã được queue
videos.id=1:
  status = 'pending' → (sẽ thay đổi khi job chạy)
  original_path = 'temp_videos/abc123.mp4'
```

### Step 5-10: Job đang xử lý
```sql
UPDATE videos SET status = 'processing', processing_started_at = NOW() WHERE id = 1;
```

### Step 11: Thành công
```sql
UPDATE videos SET 
  status = 'completed',
  hls_path = 'hls/1/playlist.m3u8',
  completed_at = NOW(),
  processing_seconds = 25.50
WHERE id = 1;
```

### Nếu lỗi
```sql
UPDATE videos SET 
  status = 'failed',
  processing_seconds = 12.30
WHERE id = 1;
-- error_message được lưu trong Redis
```

---

## 🔄 **Storage Locations**

### Local Storage (Disk: 'local')
```
storage/
├── app/
│   ├── temp_videos/           ← Upload từ user
│   │   ├── abc123.mp4         ← Video gốc
│   │   ├── def456.mp4
│   │   └── ...
│   └── ...
```

### MinIO Storage (S3-compatible, Disk: 'minio')
```
minio/
├── hls/                       ← Playlist và segment files
│   ├── 1/                     ← Video ID = 1
│   │   ├── playlist.m3u8      ← Master playlist (tất cả bitrate)
│   │   ├── stream-360p.m3u8   ← Playlist 360p
│   │   ├── stream-480p.m3u8   ← Playlist 480p
│   │   ├── stream-720p.m3u8   ← Playlist 720p
│   │   ├── stream-360p-0.ts   ← Segment 0 (360p)
│   │   ├── stream-360p-1.ts   ← Segment 1 (360p)
│   │   ├── stream-480p-0.ts   ← Segment 0 (480p)
│   │   └── ...
│   ├── 2/                     ← Video ID = 2
│   │   └── ...
│   └── ...
```

---

## ⏱️ **Thời gian xử lý**

| Độ dài video | Thời gian FFmpeg | Server specs |
|--------------|------------------|--------------|
| 5 phút       | ~20-30s          | 2 CPU, 4GB RAM |
| 30 phút      | ~2-3 phút        | 2 CPU, 4GB RAM |
| 1 giờ        | ~4-6 phút        | 2 CPU, 4GB RAM |
| 2 giờ        | ~8-12 phút       | 2 CPU, 4GB RAM |

💡 **Thời gian tuyến tính** - 30 phút video = ~2.5x thời gian 5 phút video

---

## 🛠️ **Troubleshooting**

| Vấn đề | Nguyên nhân | Giải pháp |
|--------|-----------|----------|
| Job không chạy | Queue worker không chạy | `php artisan queue:work` |
| Video status stuck 'processing' | FFmpeg lỗi im lặng | Kiểm tra logs `storage/logs/` |
| Progress không cập nhật | Redis không hoạt động | Kiểm tra Redis connection |
| MinIO không lưu file | Storage không kết nối | Kiểm tra `.env` MinIO config |
| Video file bị xóa | Dung lượng đầy | Xóa file cũ trong `temp_videos/` |

---

## 📝 **File & Class tham gia**

| Component | File | Chức năng |
|-----------|------|----------|
| **Form** | `VideoResource.php` | Upload form |
| **Create Logic** | `CreateVideo.php` | Dispatch job sau tạo |
| **Job** | `ConvertVideoForStreaming.php` | FFmpeg processing |
| **Progress Tracker** | `VideoProgressTracker.php` | Redis tracking |
| **Progress API** | `VideoProgressController.php` | Get progress endpoint |
| **Model** | `Video.php` | Database model |
| **Migrations** | `*_create_videos_table.php` | DB schema |

---

## 🎯 **Summary**

```
User Upload → Queue Dispatch → FFmpeg Process → MinIO Store → Ready for Playback
     ↓             ↓                ↓               ↓              ↓
  Filament      Database       Segments+         Streaming      HLS Player
  Form          Status         Playlists         Ready           Adaptive
             Updated          Created          Progressive      Bitrate
```

✅ **Clean architecture** - Mỗi phase độc lập, dễ debug & scale
