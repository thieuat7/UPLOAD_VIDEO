<div class="p-2 w-full flex justify-center bg-gray-900 rounded-lg shadow-inner"
     x-data="{
        initPlayer() {
            var video = document.getElementById('video-player-{{ $recordId }}');
            var videoSrc = '{{ $videoUrl }}';
            
            if (!videoSrc) {
                console.error('HLS Video URL is empty!');
                return;
            }

            // Nạp thư viện hls.js từ CDN toàn cục nếu chưa có
            if (typeof Hls !== 'undefined') {
                this.setupHls(video, videoSrc);
            } else {
                var script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/hls.js@latest';
                script.onload = () => this.setupHls(video, videoSrc);
                document.head.appendChild(script);
            }
        },
        setupHls(video, videoSrc) {
            if (Hls.isSupported()) {
                var hls = new Hls();
                hls.loadSource(videoSrc);
                hls.attachMedia(video);
            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = videoSrc;
            }
        }
     }"
     x-init="initPlayer()">
    
    @if(!empty($videoUrl))
        <video id="video-player-{{ $recordId }}" 
               controls 
               crossorigin 
               playsinline 
               class="w-full rounded shadow" 
               style="max-height: 450px; background-color: #000;">
        </video>
    @else
        <div class="text-center p-4 text-gray-400 italic">
            Không tìm thấy đường dẫn video hợp lệ từ MinIO.
        </div>
    @endif
</div>