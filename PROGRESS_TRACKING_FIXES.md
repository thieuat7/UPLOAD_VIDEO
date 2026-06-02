# 🔧 Video Progress Tracking - Fixes & Setup

## 🔴 Root Cause Found

**Problem:** Frontend not showing progress even though queue worker is running.

**Root Cause:** Redis API call was incorrect
```php
// ❌ WRONG - Redis::hmset() doesn't accept array directly
Redis::hmset($key, $data_array);

// ✅ FIXED - Properly flatten parameters
Redis::hmset($key, 'field1', 'value1', 'field2', 'value2', ...);
```

---

## ✅ Fixes Applied

### 1. **VideoProgressTracker.php** - Fixed Redis API
- Added `setHash()` helper method that properly flattens array to parameters
- Now correctly calls `Redis::hmset()` with variadic parameters
- Both `setStatus()` and `updateProgress()` use the fixed method

### 2. **VideoProgressController.php** - Enhanced Response
- Now returns both DB and Redis status
- Includes `is_synced` flag to verify data consistency
- Shows `processing_seconds` and other details
- Better error handling

### 3. **routes/api.php** - Created API Routes
- Registered endpoint: `GET /api/videos/{videoId}/progress`
- Maps to `VideoProgressController@getProgress`

### 4. **video-progress-demo.blade.php** - Frontend with Real-time Polling
- Interactive progress tracker with live updates
- Visual progress bar and status badges
- Error handling and debug info display
- Auto-stops when complete or failed

### 5. **routes/web.php** - Debug Endpoints
- `/video-progress` - Demo tracker UI
- `/debug/redis` - Test Redis connection
- `/debug/queue` - Check queue status
- `/debug/video/{id}` - View detailed video status

---

## 🚀 Quick Start

### Step 1: Verify Redis Connection
```bash
# Test via debug endpoint
curl http://localhost:8000/debug/redis

# Or use Redis CLI
redis-cli ping
# Expected: PONG
```

### Step 2: Start Queue Worker
```bash
# Terminal 1: Start queue worker
php artisan queue:work --queue=default

# Or daemonized (background)
php artisan queue:work --daemon
```

### Step 3: Upload a Video
1. Go to Filament Admin
2. Create → Videos
3. Fill Title + Upload MP4
4. Click Create

### Step 4: Track Progress
```bash
# Option A: Via browser (interactive UI)
http://localhost:8000/video-progress?videoId=1

# Option B: Via CLI (watch progress)
curl http://localhost:8000/api/videos/1/progress | jq

# Option C: Watch with polling
while true; do
  curl -s http://localhost:8000/api/videos/1/progress | jq '.progress, .current_step'
  sleep 2
done
```

---

## 📊 Expected Flow

```
Upload Video
    ↓
[0%] Initialize (Redis created: video:1)
    ↓
[10%] Setup formats (Redis progress: 10)
    ↓
[30%] FFmpeg starts (Redis progress: 30)
    ↓
[70%] Conversion finishing (Redis progress: 70)
    ↓
[100%] Complete (Redis: status=done, progress=100)
    ↓
Frontend shows "✅ Completed" with HLS path
```

---

## 🔍 Debug Checklist

### Is Redis Working?

```bash
# Check Redis status
/debug/redis
# Should see: "redis_connection": "OK ✓"

# Or test manually
redis-cli
> SET test "hello"
> GET test
> DEL test
> QUIT
```

**If Redis fails:**
- Ensure Redis server is running: `redis-server`
- Check `.env` Redis config:
  ```env
  REDIS_HOST=127.0.0.1
  REDIS_PORT=6379
  REDIS_PASSWORD=null
  ```
- Restart queue worker

### Is Queue Worker Running?

```bash
# Check queue status
/debug/queue
# Should show: "pending_jobs": X

# Verify worker process
ps aux | grep "queue:work"
# Should see process running

# If not running:
php artisan queue:work
```

### Is API Endpoint Working?

```bash
# Test endpoint directly
curl http://localhost:8000/api/videos/1/progress

# Should return:
{
  "video_id": 1,
  "db_status": "processing",
  "redis_status": "processing",
  "progress": 30,
  "current_step": "Đang chuyển đổi video HLS",
  ...
}

# If 404: Check routes/api.php exists and is registered
```

### Is Video Processing?

```bash
# View detailed status
/debug/video/1

# Check logs for errors
tail -f storage/logs/laravel.log | grep VIDEO_

# Check FFmpeg errors
grep -i "ffmpeg\|error" storage/logs/laravel.log
```

---

## 🛠️ Common Issues & Fixes

| Symptom | Root Cause | Fix |
|---------|-----------|-----|
| Progress always 0% | Redis not updating | Check Redis connection via `/debug/redis` |
| API returns 404 | Routes not registered | Ensure `routes/api.php` exists |
| Job doesn't run | Queue worker not active | Run `php artisan queue:work` |
| Progress stuck "processing" | FFmpeg error | Check `storage/logs/laravel.log` |
| Redis key expires too fast | Expiry set to 0 | Check `setExpiry(86400)` called |
| Frontend shows wrong progress | DB/Redis out of sync | Check `is_synced: true` in response |

---

## 📡 API Response Reference

### Success Response (processing)
```json
{
  "video_id": 1,
  "db_status": "processing",
  "redis_status": "processing",
  "progress": 30,
  "current_step": "Đang chuyển đổi video HLS",
  "error_message": null,
  "hls_path": null,
  "updated_at": "2026-06-02T10:00:15",
  "processing_seconds": null,
  "is_synced": true
}
```

### Success Response (completed)
```json
{
  "video_id": 1,
  "db_status": "completed",
  "redis_status": "done",
  "progress": 100,
  "current_step": null,
  "error_message": null,
  "hls_path": "hls/1/playlist.m3u8",
  "updated_at": "2026-06-02T10:00:35",
  "processing_seconds": 25.50,
  "is_synced": true
}
```

### Error Response
```json
{
  "video_id": 1,
  "db_status": "failed",
  "redis_status": "failed",
  "progress": 0,
  "current_step": null,
  "error_message": "FFmpeg: Unable to open file temp_videos/abc.mp4",
  "hls_path": null,
  "updated_at": "2026-06-02T10:00:10",
  "processing_seconds": 5.20,
  "is_synced": true
}
```

---

## 🧪 Manual Testing

### Test 1: Redis Storage
```bash
php artisan tinker

# Test writing to Redis
> $tracker = new \App\Services\VideoProgressTracker(999)
> $tracker->setStatus('testing', ['progress' => 50])
> $tracker->getStatus()
# Should see: ["status" => "testing", "progress" => 50, "updated_at" => "..."]
```

### Test 2: API Endpoint
```bash
# Upload a video first, get video ID

# Test API
curl -X GET "http://localhost:8000/api/videos/1/progress" \
  -H "Accept: application/json"

# Watch progress in real-time
watch -n 2 'curl -s http://localhost:8000/api/videos/1/progress | jq'
```

### Test 3: Queue Processing
```bash
# In terminal 1: Start worker
php artisan queue:work

# In terminal 2: Check status
curl http://localhost:8000/debug/video/1 | jq '.database.status'

# Should see status change: pending → processing → completed
```

---

## 📝 Environment Checklist

Ensure `.env` has:

```env
# Queue
QUEUE_CONNECTION=redis

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_DB=0
REDIS_CACHE_DB=1

# Cache
CACHE_DRIVER=redis

# Session
SESSION_DRIVER=file

# FFmpeg paths (if needed)
FFMPEG_PATH=/usr/bin/ffmpeg
FFPROBE_PATH=/usr/bin/ffprobe
```

---

## 🔑 Key Files Modified

| File | Change | Purpose |
|------|--------|---------|
| `src/app/Services/VideoProgressTracker.php` | Fixed Redis API | Properly store progress in Redis |
| `src/app/Http/Controllers/VideoProgressController.php` | Enhanced response | Show DB + Redis status |
| `routes/api.php` | Created | API endpoint for progress |
| `routes/web.php` | Created | Debug routes + demo UI |
| `resources/views/video-progress-demo.blade.php` | Created | Interactive frontend tracker |

---

## 🎯 Next Steps

1. **Verify fixes applied:**
   - Check `VideoProgressTracker.php` has `setHash()` method
   - Verify `routes/api.php` exists

2. **Test Redis connection:**
   - Navigate to `/debug/redis`
   - Should show "redis_connection": "OK ✓"

3. **Start queue worker:**
   - Run `php artisan queue:work`
   - Keep it running in background

4. **Upload test video:**
   - Create video in Filament
   - Note the video ID

5. **Track progress:**
   - Visit `/video-progress?videoId=1`
   - Should see real-time progress updates

6. **Verify API response:**
   - Check `/api/videos/1/progress`
   - Should show progress in JSON

---

## ✨ Summary

✅ **Fixed Redis API calls** - Now properly passing parameters  
✅ **Enhanced API response** - Shows DB + Redis status + sync flag  
✅ **Created routes** - API endpoint + debug endpoints  
✅ **Built frontend tracker** - Real-time progress with polling  
✅ **Added debug tools** - Check Redis, queue, and video status  

**Result:** Frontend will now receive real-time progress updates from Redis! 🎉
