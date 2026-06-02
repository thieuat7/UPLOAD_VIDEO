# 🎬 Video Processing Flow - Quick Reference

## 📌 30-Second Overview

```
Upload Video → Queue Job → FFmpeg Process → Upload MinIO → Ready to Play
     (UI)        (async)      (25-30s)       (S3)         (HLS Stream)
```

---

## 🚀 Complete Flow (Step by Step)

### **Phase 1: Upload (1-3 seconds)**
- User goes to Filament Admin → Videos → Create
- Fills in: Title + selects MP4 file
- Form uploads file to `storage/app/temp_videos/`
- Database record created with `status='pending'`

### **Phase 2: Queue (0.5 seconds)**
- `CreateVideo::afterCreate()` hook runs
- `ConvertVideoForStreaming::dispatch($video)` puts job in queue
- Job stored in Redis/Database queue

### **Phase 3: Processing (25-30 seconds)**
- Queue worker picks up job
- `ConvertVideoForStreaming::handle()` runs:
  1. **[0%]** Initialize: Update DB status to 'processing'
  2. **[10%]** Setup: Create 3 bitrate formats (360p/480p/720p)
  3. **[30%]** Start: FFmpeg begins encoding
  4. **[70%]** Finishing: Conversion almost done
  5. **[100%]** Complete: All segments ready
- HLS files saved to MinIO
- Original file deleted from local storage
- Redis updated with progress

### **Phase 4: Playback (Instant)**
- Status changes to 'completed'
- HLS path stored in database
- "Watch Video" button appears in admin
- Click to play video in modal with HLS.js player
- Adaptive bitrate streaming (auto quality based on network)

---

## 📊 Database Schema

```sql
-- videos table
CREATE TABLE videos (
    id BIGINT PRIMARY KEY,
    title VARCHAR(255),
    original_path VARCHAR(255),        -- temp_videos/abc.mp4
    hls_path VARCHAR(255),             -- hls/1/playlist.m3u8
    status VARCHAR(50),                -- pending, processing, completed, failed
    processing_started_at TIMESTAMP,
    completed_at TIMESTAMP,
    processing_seconds DECIMAL(10,2),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

## 🔴 Redis Progress Tracking

**Key:** `video:{id}`  
**Type:** Hash (multiple fields)

```redis
HSET video:1
    status "processing"
    progress 30
    current_step "Đang chuyển đổi video HLS"
    updated_at "2026-06-02 10:00:15"

# After success:
HSET video:1
    status "done"
    progress 100
    hls_path "hls/1/playlist.m3u8"
    
# Or on error:
HSET video:1
    status "failed"
    error_message "FFmpeg: Unable to open file"

# Auto-cleanup:
EXPIRE video:1 86400  # Delete after 24 hours
```

---

## 📁 File Structure

```
storage/
├── app/
│   └── temp_videos/          ← Original MP4 uploads
│       ├── abc123.mp4        (deleted after processing)
│       └── def456.mp4
└── logs/
    └── laravel.log           ← Check for errors

minio/
└── hls/                       ← HLS segments & playlists
    ├── 1/
    │   ├── playlist.m3u8      (master playlist - entry point)
    │   ├── stream-360p.m3u8
    │   ├── stream-480p.m3u8
    │   ├── stream-720p.m3u8
    │   ├── stream-360p-0.ts   (segments)
    │   ├── stream-360p-1.ts
    │   ├── stream-480p-0.ts
    │   └── ... (many more segments)
    └── 2/
        └── ...
```

---

## 🔧 How to Run

**Start Queue Worker:**
```bash
php artisan queue:work
# Or with specific queue:
php artisan queue:work --queue=default

# Or in background (long-running):
php artisan queue:work --daemon
```

**Check Queue Status:**
```bash
# See pending jobs
php artisan queue:failed-table

# Retry failed job
php artisan queue:retry job-id

# Clear all jobs
php artisan queue:flush
```

---

## 📡 API Endpoints

### Get Progress (Real-time monitoring)
```http
GET /api/videos/{videoId}/progress

Response:
{
  "video_id": 1,
  "status": "processing",
  "progress": 30,
  "current_step": "Đang chuyển đổi video HLS",
  "error_message": null,
  "hls_path": null,
  "updated_at": "2026-06-02T10:00:15"
}
```

**Status Values:**
- `processing` - FFmpeg is encoding (progress: 0-100)
- `done` - Completed successfully (progress: 100)
- `failed` - Error occurred (error_message filled)

---

## ⚡ Performance Benchmarks

| Video Length | Processing Time | Output Size |
|-------------|-----------------|------------|
| 5 minutes   | 25-30 seconds   | 50-100 MB  |
| 30 minutes  | 2-3 minutes     | 300-600 MB |
| 1 hour      | 4-6 minutes     | 600-1200 MB |

---

## 🐛 Troubleshooting

| Issue | Check | Solution |
|-------|-------|----------|
| Job not running | Queue worker process | `php artisan queue:work` |
| Status stuck "processing" | Log file for FFmpeg error | Check `storage/logs/laravel.log` |
| Progress not updating | Redis connection | `redis-cli ping` → should return PONG |
| MinIO files not saving | Storage config | Check `.env` MinIO credentials |
| Video file deleted before completion | Timeout too short | Increase `$timeout = 3600` in job |
| Progress API returns 404 | Redis key expired | Key expires after 24 hours |

---

## 🔐 Security Notes

✅ **What's Secure:**
- Original file deleted after processing (no leftover)
- HLS segments served from MinIO (signed URLs)
- Job serialized safely in Redis
- Error messages logged, not exposed to user

⚠️ **Best Practices:**
- Use signed URLs for MinIO (time-limited access)
- Validate user can access specific video
- Monitor queue worker uptime
- Set reasonable timeout (3600s = 1 hour)

---

## 📝 Key Classes

| Class | Location | Purpose |
|-------|----------|---------|
| `ConvertVideoForStreaming` | `app/Jobs/` | Main job handler |
| `VideoProgressTracker` | `app/Services/` | Redis progress tracking |
| `VideoProgressController` | `app/Http/Controllers/` | API for progress status |
| `VideoResource` | `app/Filament/Resources/` | Admin UI (Filament) |
| `CreateVideo` | `app/Filament/Resources/Pages/` | Create page hook |

---

## 🎯 Status Lifecycle

```
┌─────────┐
│ pending │  User created, waiting for worker
└────┬────┘
     │
     ↓
┌───────────┐
│processing │  Job running, FFmpeg encoding
└────┬──────┘
     │
     ├─ Success? ─→ ┌───────────┐
     │              │ completed │  Done! Ready to play
     │              └───────────┘
     │
     └─ Error? ───→ ┌────────┐
                    │ failed │  Error occurred
                    └────────┘
```

---

## 🎬 Example: Upload to Play

**Timeline:**
```
10:00:00  → User uploads "Laravel Course Part 1.mp4"
10:00:03  → Job dispatched (database: pending)
10:00:05  → Queue worker starts processing
10:00:05  → [0%] Initialize (status: processing)
10:00:06  → [10%] Setup formats
10:00:08  → [30%] FFmpeg starts encoding
10:00:30  → [70%] Conversion nearly complete
10:00:33  → [100%] Success! (status: completed)
10:00:34  → "Watch Video" button appears
10:00:35  → User clicks, modal opens with HLS player
10:00:36  → Video starts playing adaptive bitrate
```

**Duration:** ~36 seconds from upload to playback ready ⚡

---

## 📚 For More Details

See full documentation:
- **WORKFLOW.md** - Detailed step-by-step breakdown
- **ARCHITECTURE.md** - System diagrams and component interactions
- **Code Comments** - In-line explanations in PHP files

---

## ✨ Summary

Video processing is **fully automated** after upload:
1. ✅ User uploads in admin panel
2. ✅ Job queued automatically
3. ✅ FFmpeg processes in background (~30 seconds)
4. ✅ Redis tracks progress real-time
5. ✅ Files stored in MinIO (S3-compatible)
6. ✅ Ready for playback via HLS player
7. ✅ Adaptive quality based on network speed

No manual steps needed! 🎉
