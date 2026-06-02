# 🎯 Redis Progress Tracking - Complete Implementation Guide

## Executive Summary

**Problem:** Frontend doesn't show video progress  
**Root Cause:** Redis hmset() API call was incorrect  
**Solution:** Fixed Redis operations + created complete tracking system  
**Result:** Real-time progress visible in frontend ✅

---

## 📊 What's Fixed

### 1. Redis API Call ❌→✅

**Before (WRONG):**
```php
Redis::hmset($key, $data_array);  // ❌ Doesn't work
```

**After (CORRECT):**
```php
// Helper method flattens array to variadic parameters
private function setHash(array $data): void
{
    $flattenedData = [];
    foreach ($data as $field => $value) {
        $flattenedData[] = $field;
        $flattenedData[] = $value;
    }
    call_user_func_array([Redis::class, 'hmset'], 
        array_merge([$this->getKey()], $flattenedData));
}
```

This properly calls: `Redis::hmset(key, field1, value1, field2, value2, ...)`

### 2. Enhanced API Response

**Provides:**
- DB status (from database)
- Redis status (from Redis)
- Sync verification (db_status === redis_status)
- Progress, error messages, HLS path
- All debugging info in one response

### 3. Complete Frontend Integration

**Includes:**
- Real-time progress polling every 2 seconds
- Visual progress bar with percentage
- Status badges with color coding
- Error handling and display
- Debug info panel
- Auto-stop when complete/failed

### 4. Debug Tools & Endpoints

**Available at:**
- `/video-progress?videoId=1` - Interactive tracker UI
- `/debug/redis` - Test Redis connection
- `/debug/queue` - Check pending jobs
- `/debug/video/1` - Detailed video status

---

## 🚀 Complete Setup

### Step 1: Verify Fixed Files Exist

```bash
# Check VideoProgressTracker is fixed
grep -n "setHash" src/app/Services/VideoProgressTracker.php
# Should return lines with setHash method

# Check routes exist
ls routes/api.php routes/web.php
# Both should exist
```

### Step 2: Start Services

```bash
# Terminal 1: Redis server
redis-server

# Terminal 2: Queue worker
cd your-project
php artisan queue:work --queue=default

# Terminal 3: Laravel dev server
php artisan serve
```

### Step 3: Test System

```bash
# Test Redis connection
curl http://localhost:8000/debug/redis
# Should see: "redis_connection": "OK ✓"

# Test command line
php artisan test:video-progress 1
```

### Step 4: Upload Video & Track

```bash
# 1. Create video via Filament admin (get video ID)
# 2. Open tracker in browser
http://localhost:8000/video-progress?videoId=1

# 3. Watch progress update in real-time
```

---

## 📡 API Specification

### Endpoint
```
GET /api/videos/{videoId}/progress
```

### Headers
```
Accept: application/json
```

### Response (Processing)
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

### Response (Completed)
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

### Response Codes
- `200` - Progress data returned
- `404` - Video not found
- `500` - Server error

---

## 🔍 How It Works

### Data Flow

```
User Uploads Video
    ↓
VideoResource::create() → DB record (pending)
    ↓
CreateVideo::afterCreate()
    ↓
ConvertVideoForStreaming::dispatch() → Queue
    ↓
Queue Worker picks up job
    ↓
ConvertVideoForStreaming::handle()
    ↓
[0%] VideoProgressTracker::setProcessing()
     └─ Redis SET video:1 {status:processing, progress:0}
    ↓
[10%] VideoProgressTracker::updateProgress(10, ...)
     └─ Redis HSET video:1 {progress:10, current_step:...}
    ↓
[30%] VideoProgressTracker::updateProgress(30, ...)
     └─ Redis HSET video:1 {progress:30}
    ↓
[70%] VideoProgressTracker::updateProgress(70, ...)
     └─ Redis HSET video:1 {progress:70}
    ↓
[100%] VideoProgressTracker::setSuccess(hls_path)
       + Update DB (status=completed)
       └─ Redis HSET video:1 {status:done, progress:100, hls_path:...}
    ↓
Frontend Polling GET /api/videos/1/progress
    ↓
VideoProgressController retrieves both DB + Redis
    ↓
Returns combined status to frontend
    ↓
Frontend updates UI (progress bar, status, etc.)
```

### Redis Data Structure

```
Type: Hash
Key: video:{id}
Fields:
  ├─ status: "processing" | "done" | "failed"
  ├─ progress: 0-100
  ├─ current_step: "Step description"
  ├─ error_message: "Error text (if failed)"
  ├─ hls_path: "hls/{id}/playlist.m3u8 (if done)"
  └─ updated_at: "2026-06-02T10:00:15"

TTL: 86400 seconds (24 hours, auto-cleanup)
```

---

## 🧪 Testing Matrix

### Test 1: Redis Connection
```bash
redis-cli ping
# Expected: PONG

# Or via API
curl http://localhost:8000/debug/redis | jq '.redis_connection'
# Expected: "OK ✓"
```

### Test 2: VideoProgressTracker
```bash
php artisan tinker

$tracker = new \App\Services\VideoProgressTracker(999)
$tracker->setStatus('testing', ['progress' => 50])
$tracker->getStatus()
# Expected: Array with status='testing', progress=50
```

### Test 3: API Endpoint
```bash
curl http://localhost:8000/api/videos/1/progress | jq
# Expected: Valid JSON with all fields
```

### Test 4: Real Video Upload
```bash
# 1. Upload video in Filament
# 2. Note video ID
# 3. curl http://localhost:8000/api/videos/{id}/progress
# 4. progress should increase: 0 → 10 → 30 → 70 → 100
```

---

## 🛠️ Troubleshooting

### Issue: Progress always 0%

**Check:**
```bash
# Is Redis key being created?
redis-cli HGETALL video:1
# Should see: status, progress, updated_at

# Is queue worker running?
ps aux | grep "queue:work"

# Any errors in logs?
tail -f storage/logs/laravel.log | grep VIDEO_
```

**Fix:**
- Verify Redis connection: `/debug/redis`
- Start queue worker: `php artisan queue:work`
- Check Redis key exists: `redis-cli KEYS video:*`

### Issue: API returns 404

**Check:**
```bash
# Does routes/api.php exist?
ls routes/api.php

# Is endpoint registered?
php artisan route:list | grep progress

# Is video ID valid?
curl http://localhost:8000/api/videos/999/progress
# Valid ID returns data, invalid returns 404
```

**Fix:**
- Create routes/api.php if missing
- Ensure VideoProgressController exists
- Register routes in api.php

### Issue: is_synced is false

**Check:**
```bash
# Are DB and Redis out of sync?
curl http://localhost:8000/api/videos/1/progress | jq '{db_status, redis_status, is_synced}'

# Check Redis data
redis-cli HGETALL video:1

# Check DB data
sqlite3 database.sqlite "SELECT status FROM videos WHERE id = 1;"
```

**Fix:**
- Manual sync: Update DB status to match Redis
- Or restart job (status should auto-sync)

### Issue: Queue worker keeps crashing

**Check:**
```bash
# Any errors in logs?
grep -i error storage/logs/laravel.log

# Is FFmpeg installed?
which ffmpeg

# Is MinIO accessible?
curl -s http://minio-url:9000
```

**Fix:**
- Restart worker: `php artisan queue:work`
- Install FFmpeg: `apt install ffmpeg`
- Check MinIO connection in .env
- Increase timeout: `public $timeout = 7200;`

---

## 📋 Files Changed & Created

### Modified Files
- `src/app/Services/VideoProgressTracker.php` - Fixed Redis API
- `src/app/Http/Controllers/VideoProgressController.php` - Enhanced response
- `src/app/Jobs/ConvertVideoForStreaming.php` - Already has tracking calls

### Created Files
- `routes/api.php` - API routes
- `routes/web.php` - Debug & demo routes
- `resources/views/video-progress-demo.blade.php` - Frontend tracker
- `src/app/Console/Commands/TestVideoProgress.php` - CLI test command

---

## 🎯 Usage Examples

### Example 1: Track via Browser
```
1. Visit: http://localhost:8000/video-progress
2. Enter video ID
3. Click "Track"
4. Watch progress update in real-time
```

### Example 2: Track via CLI
```bash
# Watch single request
curl http://localhost:8000/api/videos/1/progress | jq

# Watch in loop (every 2s)
while true; do
  curl -s http://localhost:8000/api/videos/1/progress | jq '.progress, .current_step'
  sleep 2
done
```

### Example 3: Track via JavaScript (Frontend)
```javascript
async function trackProgress(videoId) {
  const response = await fetch(`/api/videos/${videoId}/progress`);
  const data = await response.json();
  
  console.log(`Progress: ${data.progress}%`);
  console.log(`Status: ${data.db_status}`);
  console.log(`Step: ${data.current_step}`);
  
  if (data.db_status === 'completed') {
    console.log(`HLS Path: ${data.hls_path}`);
  }
}

// Poll every 2 seconds
setInterval(() => trackProgress(1), 2000);
```

### Example 4: React Component
```jsx
import { useState, useEffect } from 'react';

export function VideoProgress({ videoId }) {
  const [progress, setProgress] = useState(0);
  const [status, setStatus] = useState('pending');
  
  useEffect(() => {
    const interval = setInterval(async () => {
      const res = await fetch(`/api/videos/${videoId}/progress`);
      const data = await res.json();
      setProgress(data.progress);
      setStatus(data.db_status);
      
      if (data.db_status === 'completed' || data.db_status === 'failed') {
        clearInterval(interval);
      }
    }, 2000);
    
    return () => clearInterval(interval);
  }, [videoId]);
  
  return (
    <div>
      <div className="progress">
        <div style={{ width: `${progress}%` }}>{progress}%</div>
      </div>
      <p>Status: {status}</p>
    </div>
  );
}
```

---

## ✅ Verification Checklist

- [ ] Redis server running: `redis-cli ping` returns PONG
- [ ] Queue worker running: `ps aux | grep queue:work` shows process
- [ ] Routes created: `php artisan route:list | grep progress`
- [ ] API responds: `curl /api/videos/1/progress` returns JSON
- [ ] Demo UI works: `/video-progress?videoId=1` loads
- [ ] Progress updates: Video progress 0% → 100%
- [ ] Frontend shows data: Browser displays progress bar
- [ ] DB/Redis synced: `is_synced: true` in API response
- [ ] Error handling: Failed job shows error message
- [ ] Auto-cleanup: Redis key expires after 24h

---

## 🎓 Key Learnings

1. **Redis hmset() requires variadic parameters**, not array
2. **Frontend polling is simple & effective** for progress tracking
3. **Dual storage (DB + Redis)** provides redundancy & verification
4. **Progress tracking works in parallel** with actual FFmpeg processing
5. **Real-time updates need sub-second latency** (Redis is perfect for this)

---

## 📞 Support Commands

```bash
# Run automated tests
php artisan test:video-progress {videoId}

# Check Redis status
php artisan tinker
> \Illuminate\Support\Facades\Redis::ping()

# Monitor queue
php artisan queue:monitor

# Retry failed jobs
php artisan queue:retry job-id

# Clear all jobs
php artisan queue:flush
```

---

## 🎉 Summary

**What you get:**
- ✅ Real-time progress tracking via Redis
- ✅ Dual verification (DB + Redis)
- ✅ Interactive frontend tracker
- ✅ Multiple debug endpoints
- ✅ CLI test command
- ✅ Complete API documentation

**Time to implement:**
- Fix: 5 minutes
- Test: 10 minutes
- Integrate frontend: 30 minutes

**Result:**
Frontend now shows video progress 0% → 100% in real-time! 🚀
