# 🏗️ Video Processing Architecture

## System Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         USER INTERFACE (Filament Admin)                 │
│                                                                          │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │ Create Video Form                                                │  │
│  │ ┌──────────────────────────────────────────────────────────────┐ │  │
│  │ │ Title: [_________________]                                   │ │  │
│  │ │ Video: [Choose File] → abc123.mp4                            │ │  │
│  │ │                                                               │ │  │
│  │ │                                    [Create] button            │ │  │
│  │ └──────────────────────────────────────────────────────────────┘ │  │
│  └──────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
                        CreateVideo::afterCreate()
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│                    STEP 1: INITIAL DATA STORAGE                         │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  Local Filesystem               Database (MySQL/PostgreSQL)             │
│  ┌──────────────────────┐       ┌─────────────────────────────────┐    │
│  │ storage/app/         │       │ videos table                    │    │
│  │ └── temp_videos/     │       │ ┌─────────────────────────────┐ │    │
│  │     └── abc123.mp4   │       │ │ id: 1                       │ │    │
│  │        (50 MB)       │       │ │ title: "PHP 101"            │ │    │
│  │                      │       │ │ original_path: temp_...mp4  │ │    │
│  └──────────────────────┘       │ │ hls_path: NULL              │ │    │
│                                  │ │ status: "pending"           │ │    │
│                                  │ │ created_at: NOW()           │ │    │
│                                  │ └─────────────────────────────┘ │    │
│                                  └─────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
                    ConvertVideoForStreaming::dispatch()
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│                    STEP 2: QUEUE SYSTEM (Redis/Database)               │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  Redis (In-memory Queue)        Database (Jobs Table)                  │
│  ┌──────────────────────┐       ┌─────────────────────────────────┐    │
│  │ QUEUE: default       │       │ jobs table                      │    │
│  │ [Job#1]              │       │ ┌─────────────────────────────┐ │    │
│  │ [Job#2] ← Current    │       │ │ id: 1                       │ │    │
│  │ [Job#3]              │       │ │ queue: "default"            │ │    │
│  │                      │       │ │ payload: JSON(Job#1)        │ │    │
│  └──────────────────────┘       │ │ attempts: 0                 │ │    │
│                                  │ │ reserved_at: NULL           │ │    │
│                                  │ └─────────────────────────────┘ │    │
│                                  └─────────────────────────────────┘    │
│                                                                          │
│  Also storing in Redis:                                                │
│  ┌──────────────────────┐                                              │
│  │ video:1 (Hash)       │                                              │
│  │ ├── status: pending  │                                              │
│  │ ├── progress: 0      │                                              │
│  │ └── updated_at: NOW()│                                              │
│  └──────────────────────┘                                              │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
                    Queue Worker: php artisan queue:work
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│                 STEP 3: PROCESSING PHASE (FFmpeg Job)                  │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ConvertVideoForStreaming::handle()                                    │
│                                                                          │
│  [0%] Initialize                                                        │
│  └─ Update DB: status='processing', processing_started_at=NOW()       │
│  └─ Update Redis: video:1 {status: processing, progress: 0}           │
│                          ↓                                              │
│  [10%] Setup Formats                                                    │
│  └─ Create 3 bitrate profiles:                                         │
│      • 250kbps, 360p (scale=-2:360)                                    │
│      • 500kbps, 480p (scale=-2:480)                                    │
│      • 1000kbps, 720p (scale=-2:720)                                   │
│  └─ Update Redis: progress=10                                          │
│                          ↓                                              │
│  [30%] FFmpeg Encoding START                                            │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │ FFMpeg::fromDisk('local')                                       │   │
│  │   .open('temp_videos/abc123.mp4')                               │   │
│  │   .exportForHLS()                                               │   │
│  │   .toDisk('minio')                                              │   │
│  │   .addFormat(lowBitrate, scale=-2:360)                          │   │
│  │   .addFormat(midBitrate, scale=-2:480)                          │   │
│  │   .addFormat(highBitrate, scale=-2:720)                         │   │
│  │   .save('hls/1/playlist.m3u8')                                  │   │
│  └─────────────────────────────────────────────────────────────────┘   │
│  └─ Update Redis: progress=30                                          │
│                          ↓                                              │
│  [⏳ Processing... 30+ seconds]                                        │
│  └─ FFmpeg generates HLS segments:                                     │
│      hls/1/stream-360p-0.ts                                            │
│      hls/1/stream-360p-1.ts  ... (multiple)                            │
│      hls/1/stream-480p-0.ts  ... (multiple)                            │
│      hls/1/stream-720p-0.ts  ... (multiple)                            │
│      hls/1/stream-360p.m3u8                                            │
│      hls/1/stream-480p.m3u8                                            │
│      hls/1/stream-720p.m3u8                                            │
│      hls/1/playlist.m3u8 (MASTER PLAYLIST)                             │
│                          ↓                                              │
│  [70%] Encoding Complete                                               │
│  └─ Update Redis: progress=70                                          │
│                          ↓                                              │
│  [100%] Success ✅                                                      │
│  └─ Delete original: temp_videos/abc123.mp4 (free up space)           │
│  └─ Update DB:                                                         │
│      • status='completed'                                              │
│      • hls_path='hls/1/playlist.m3u8'                                  │
│      • completed_at=NOW()                                              │
│      • processing_seconds=25.50                                        │
│  └─ Update Redis:                                                      │
│      • video:1 {status: done, progress: 100, hls_path: ...}           │
│      • EXPIRE 86400 (1 day auto-cleanup)                               │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
              (On Error: CATCH block, status='failed')
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│              STEP 4: STORAGE LAYOUT (MinIO S3-Compatible)               │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  MinIO Bucket Structure:                                               │
│                                                                          │
│  minio/                                                                 │
│  └── hls/                   (HLS streaming folder)                      │
│      ├── 1/                 (Video ID = 1)                             │
│      │   ├── playlist.m3u8  (✅ Master Playlist - Entry Point)         │
│      │   │                                                              │
│      │   ├── stream-360p.m3u8   (360p variant playlist)               │
│      │   ├── stream-360p-0.ts                                         │
│      │   ├── stream-360p-1.ts                                         │
│      │   ├── stream-360p-2.ts                                         │
│      │   │   ... (10-30 segments, ~10s each)                          │
│      │   │                                                              │
│      │   ├── stream-480p.m3u8   (480p variant playlist)               │
│      │   ├── stream-480p-0.ts                                         │
│      │   ├── stream-480p-1.ts                                         │
│      │   │   ... (10-30 segments)                                     │
│      │   │                                                              │
│      │   ├── stream-720p.m3u8   (720p variant playlist)               │
│      │   ├── stream-720p-0.ts                                         │
│      │   ├── stream-720p-1.ts                                         │
│      │   │   ... (10-30 segments)                                     │
│      │   │                                                              │
│      │   └── total: ~150-300 MB (depending on source video)           │
│      │                                                                  │
│      ├── 2/                 (Video ID = 2)                             │
│      │   ├── playlist.m3u8                                             │
│      │   └── ...                                                       │
│      │                                                                  │
│      └── 3/                 (Video ID = 3)                             │
│          └── ...                                                       │
│                                                                          │
│  Playlist Content Example:                                             │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │ hls/1/playlist.m3u8                                             │   │
│  │ #EXTM3U                                                         │   │
│  │ #EXT-X-VERSION:3                                               │   │
│  │ #EXT-X-STREAM-INF:BANDWIDTH=250000,RESOLUTION=640x360         │   │
│  │ stream-360p.m3u8                                               │   │
│  │ #EXT-X-STREAM-INF:BANDWIDTH=500000,RESOLUTION=854x480         │   │
│  │ stream-480p.m3u8                                               │   │
│  │ #EXT-X-STREAM-INF:BANDWIDTH=1000000,RESOLUTION=1280x720       │   │
│  │ stream-720p.m3u8                                               │   │
│  └─────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│           STEP 5: FRONTEND - PROGRESS MONITORING (Optional)            │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  Client-side Polling:                                                  │
│  setInterval(() => {                                                   │
│    GET /api/videos/1/progress                                          │
│  }, 2000) // Every 2 seconds                                           │
│                                                                          │
│  Redis Response:                                                        │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │ {                                                               │   │
│  │   "video_id": 1,                                                │   │
│  │   "status": "processing",                                       │   │
│  │   "progress": 30,                                               │   │
│  │   "current_step": "Đang chuyển đổi video HLS",                 │   │
│  │   "error_message": null,                                        │   │
│  │   "hls_path": null,                                             │   │
│  │   "updated_at": "2026-06-02T10:00:15"                          │   │
│  │ }                                                               │   │
│  └─────────────────────────────────────────────────────────────────┘   │
│                          ↓                                              │
│  UI Progress Bar:  [=========>           ] 30%                         │
│  Status Text:      "Đang chuyển đổi video HLS"                         │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│        STEP 6: PLAYBACK - VIDEO READY (After Processing Done)         │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  List Videos View (Updated):                                           │
│  ┌────────────────────────────────────────────────────────────────┐    │
│  │ Videos Table                                                   │    │
│  │ ┌───────────┬──────────┬──────────────────────────────────────┐    │
│  │ │ Title     │ Status   │ Actions                              │    │
│  │ ├───────────┼──────────┼──────────────────────────────────────┤    │
│  │ │ PHP 101   │ ✅ Done  │ [Edit] [✅ Watch Video] [Delete]    │    │
│  │ │ Django 1  │ ⏳ Proc. │ [Edit] [Delete]                      │    │
│  │ │ React 2   │ ❌ Failed│ [Edit] [Delete]                      │    │
│  │ └───────────┴──────────┴──────────────────────────────────────┘    │
│  └────────────────────────────────────────────────────────────────┘    │
│                                                                          │
│  Click "Watch Video" → Modal opens:                                   │
│  ┌────────────────────────────────────────────────────────────────┐    │
│  │ Watching: PHP 101                             ✕                │    │
│  │ ┌────────────────────────────────────────────────────────────┐│    │
│  │ │                                                             ││    │
│  │ │          HLS Video Player (HLS.js)                         ││    │
│  │ │          ▶ ▮▮▮▮▮▮░░░░░░░░  3:45 / 5:00 (720p)           ││    │
│  │ │          ◄ 10s  ⊙ Settings  Speed  Quality  ⛶             ││    │
│  │ │                                                             ││    │
│  │ │  [Video Content Playing Smoothly]                          ││    │
│  │ │                                                             ││    │
│  │ └────────────────────────────────────────────────────────────┘│    │
│  │ ┌────────────────────────────────────────────────────────────┐│    │
│  │ │ Bitrate: Auto | Quality: 720p | Status: ✅ Streaming      ││    │
│  │ └────────────────────────────────────────────────────────────┘│    │
│  │                                  [Close]                       │    │
│  └────────────────────────────────────────────────────────────────┘    │
│                                                                          │
│  HLS.js Player Logic:                                                  │
│  ┌────────────────────────────────────────────────────────────────┐    │
│  │ 1. Load playlist.m3u8 (master)                                │    │
│  │ 2. Parse all variant playlists (360p, 480p, 720p)             │    │
│  │ 3. Detect network speed & device capability                   │    │
│  │ 4. Start playback at appropriate bitrate                      │    │
│  │ 5. Monitor network conditions                                 │    │
│  │    • Network fast → upgrade to 720p                           │    │
│  │    • Network slow → downgrade to 480p or 360p                 │    │
│  │ 6. Download & play segments sequentially                      │    │
│  │ 7. Continuous buffer ahead of playhead                        │    │
│  └────────────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## 🔄 State Transitions

```
Database Status Transitions:

User Creates Video
        ↓
    pending ─────────────┐
        ↓                 │
    Dispatch Job         │
        ↓                 │
    processing ◄─────────┘
        ↓
     [30% FFmpeg encoding...]
        ↓
    completed ✅          ❌ Error
        ↓                  ↓
    Ready for Play    failed
        ↓                  ↓
    [Playing...]      [Retry/Delete]


Redis Progress States:

    processing (0%)
        ↓
    processing (10%)
        ↓
    processing (30%)
        ↓
    processing (70%)
        ↓
    done (100%) ✅
        ↓ (or ❌ Error)
    failed + error_message
        ↓
    [Auto-expire after 24h]
```

---

## 🗄️ Component Interaction Map

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Components Interaction                        │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  VideoResource (Filament)                                           │
│  ├─ form() → FileUpload, TextInput                                 │
│  └─ table() → Show status, play button                             │
│       ├─ visible → Check status='completed' && hls_path             │
│       └─ action → Play Video (modal with HLS player)               │
│            └─ video-player view (Blade)                            │
│                                                                      │
│  CreateVideo Page                                                   │
│  └─ afterCreate() → ConvertVideoForStreaming::dispatch()           │
│       └─ Job queued                                                │
│                                                                      │
│  ConvertVideoForStreaming Job                                      │
│  ├─ __construct(Video $video)                                      │
│  ├─ handle()                                                        │
│  │  └─ try/catch wrapper                                           │
│  │     ├─ VideoProgressTracker (Redis)                             │
│  │     │  ├─ setProcessing()                                       │
│  │     │  ├─ updateProgress(10, 30, 70)                           │
│  │     │  ├─ setSuccess(hls_path)                                  │
│  │     │  └─ setFailed(error_message)                              │
│  │     └─ FFMpeg (encoding)                                        │
│  │        └─ save() → MinIO                                        │
│  └─ On success: DB updated + Redis set + File deleted             │
│  └─ On error: DB updated + Redis error + Exception thrown          │
│                                                                      │
│  VideoProgressController                                           │
│  └─ getProgress(videoId)                                           │
│     └─ VideoProgressTracker::getStatus() → Redis                   │
│        └─ Returns JSON with progress, status, error, etc.          │
│                                                                      │
│  Storage Disks:                                                     │
│  ├─ local → temp_videos/ (original uploads)                        │
│  └─ minio → hls/ (processed HLS streams)                           │
│                                                                      │
│  Queue System:                                                      │
│  ├─ Redis → Job queue                                              │
│  ├─ Database → Jobs table (fallback)                               │
│  └─ Worker → Listens & processes jobs                              │
│                                                                      │
│  Databases:                                                         │
│  ├─ MySQL/PostgreSQL → Videos table                                │
│  └─ Redis → Progress tracking (video:{id})                         │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

---

## ⚡ Performance Metrics

```
Encoding Performance (on 2CPU, 4GB RAM):
┌──────────────┬─────────────┬──────────────┐
│ Video Length │ Encode Time │ Output Size  │
├──────────────┼─────────────┼──────────────┤
│ 5 mins       │ 25-30s      │ 50-100 MB    │
│ 30 mins      │ 2-3 min     │ 300-600 MB   │
│ 1 hour       │ 4-6 min     │ 600-1200 MB  │
│ 2 hours      │ 8-12 min    │ 1.2-2.4 GB   │
└──────────────┴─────────────┴──────────────┘

Segment Count (5-minute video):
┌─────────┬──────────┬──────────────┐
│ Quality │ Bitrate  │ Segments (#) │
├─────────┼──────────┼──────────────┤
│ 360p    │ 250 kbps │ ~13          │
│ 480p    │ 500 kbps │ ~13          │
│ 720p    │ 1000kbps │ ~13          │
└─────────┴──────────┴──────────────┘
Total segments per video: ~39 files + 4 playlists = 43 files
```

---

## 🔒 Error Handling Flow

```
FFmpeg Execution Error
        ↓
    Exception thrown
        ↓
    CATCH block triggered
        ↓
    ┌─────────────────────────────────────┐
    │ 1. Calculate processing_seconds     │
    │ 2. Update DB: status='failed'       │
    │ 3. Log error details (message,file) │
    │ 4. Update Redis: error_message      │
    │ 5. Throw exception (Queue retries)  │
    └─────────────────────────────────────┘
        ↓
    Queue decides:
    ├─ Retry (if < max_attempts)
    │   └─ Job re-queued
    └─ Failed (if >= max_attempts)
        └─ Job moved to 'failed' table
```

✅ All flows documented and clean!
