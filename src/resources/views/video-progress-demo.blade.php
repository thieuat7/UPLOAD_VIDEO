<!-- Video Progress Tracking Example -->
<div id="video-progress-container" style="max-width: 600px; margin: 20px auto;">
    <h2>Video Processing Progress</h2>

    <div style="margin: 20px 0;">
        <label>Video ID: <input type="number" id="videoId" value="1" style="width: 80px; padding: 5px;"></label>
        <button onclick="startTracking()" style="padding: 8px 15px; cursor: pointer;">Track</button>
        <button onclick="stopTracking()" style="padding: 8px 15px; cursor: pointer;">Stop</button>
    </div>

    <!-- Status Badge -->
    <div style="padding: 15px; background: #f0f0f0; border-radius: 5px; margin: 10px 0;">
        <h3 style="margin: 0 0 10px 0;">Status: <span id="status-badge" style="
            display: inline-block;
            padding: 5px 10px;
            border-radius: 3px;
            background: #999;
            color: white;
            font-weight: bold;
        ">Waiting...</span></h3>
    </div>

    <!-- Progress Bar -->
    <div style="margin: 20px 0;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
            <span>Progress</span>
            <span id="progress-text">0%</span>
        </div>
        <div style="width: 100%; height: 30px; background: #ddd; border-radius: 5px; overflow: hidden;">
            <div id="progress-bar" style="
                width: 0%;
                height: 100%;
                background: linear-gradient(90deg, #4CAF50, #45a049);
                transition: width 0.3s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-weight: bold;
                font-size: 12px;
            ">
            </div>
        </div>
    </div>

    <!-- Current Step -->
    <div style="padding: 10px; background: #e8f5e9; border-left: 4px solid #4CAF50; margin: 10px 0;">
        <strong>Current Step:</strong>
        <p id="current-step" style="margin: 5px 0; color: #666;">Waiting for job to start...</p>
    </div>

    <!-- Details -->
    <div style="padding: 15px; background: #f9f9f9; border-radius: 5px; margin: 20px 0;">
        <h4>Details:</h4>
        <table style="width: 100%; font-size: 14px;">
            <tr>
                <td><strong>DB Status:</strong></td>
                <td id="db-status">-</td>
            </tr>
            <tr>
                <td><strong>Redis Status:</strong></td>
                <td id="redis-status">-</td>
            </tr>
            <tr>
                <td><strong>HLS Path:</strong></td>
                <td id="hls-path" style="word-break: break-all;">-</td>
            </tr>
            <tr>
                <td><strong>Processing Time:</strong></td>
                <td id="processing-time">-</td>
            </tr>
            <tr>
                <td><strong>Last Updated:</strong></td>
                <td id="updated-at">-</td>
            </tr>
            <tr>
                <td><strong>DB/Redis Synced:</strong></td>
                <td id="is-synced" style="color: green;">✓</td>
            </tr>
        </table>
    </div>

    <!-- Error Message -->
    <div id="error-container" style="display: none; padding: 15px; background: #ffebee; border-left: 4px solid #f44336; margin: 10px 0;">
        <strong style="color: #c62828;">Error:</strong>
        <p id="error-message" style="margin: 5px 0; color: #b71c1c;"></p>
    </div>

    <!-- Debug Info -->
    <div style="padding: 15px; background: #f5f5f5; border-radius: 5px; margin: 20px 0;">
        <h4>Debug Info:</h4>
        <pre id="debug-info" style="
            background: #000;
            color: #0f0;
            padding: 10px;
            border-radius: 3px;
            font-size: 12px;
            overflow-x: auto;
            max-height: 200px;
        "></pre>
    </div>
</div>

<script>
let pollingInterval = null;
let apiUrl = '/api/videos/{videoId}/progress';

function startTracking() {
    const videoId = document.getElementById('videoId').value;
    if (!videoId) {
        alert('Please enter a video ID');
        return;
    }

    apiUrl = `/api/videos/${videoId}/progress`;
    console.log('Starting progress tracking for video:', videoId);
    updateProgress();
    pollingInterval = setInterval(updateProgress, 2000); // Poll every 2 seconds
}

function stopTracking() {
    if (pollingInterval) {
        clearInterval(pollingInterval);
        pollingInterval = null;
        console.log('Stopped tracking');
    }
}

function updateProgress() {
    fetch(apiUrl)
        .then(response => {
            if (response.status === 404) {
                showError('Video not found');
                return null;
            }
            return response.json();
        })
        .then(data => {
            if (!data) return;

            console.log('Progress update:', data);

            // Update status badge
            const statusBadge = document.getElementById('status-badge');
            statusBadge.textContent = data.db_status || 'unknown';
            statusBadge.style.background = getStatusColor(data.db_status);

            // Update progress bar
            const progress = data.progress || 0;
            document.getElementById('progress-bar').style.width = progress + '%';
            document.getElementById('progress-bar').textContent = progress + '%';
            document.getElementById('progress-text').textContent = progress + '%';

            // Update current step
            document.getElementById('current-step').textContent = data.current_step || 'No step info';

            // Update details
            document.getElementById('db-status').textContent = data.db_status || '-';
            document.getElementById('redis-status').textContent = data.redis_status || '-';
            document.getElementById('hls-path').textContent = data.hls_path || '-';
            document.getElementById('processing-time').textContent = data.processing_seconds ?
                data.processing_seconds + 's' : '-';
            document.getElementById('updated-at').textContent = data.updated_at || '-';
            document.getElementById('is-synced').textContent = data.is_synced ? '✓ Synced' : '✗ Out of sync';
            document.getElementById('is-synced').style.color = data.is_synced ? '#4CAF50' : '#f44336';

            // Handle error
            if (data.error_message) {
                showError(data.error_message);
            } else {
                hideError();
            }

            // Update debug info
            updateDebugInfo(data);

            // Stop polling if completed or failed
            if (data.db_status === 'completed' || data.db_status === 'failed') {
                stopTracking();
            }
        })
        .catch(error => {
            console.error('Error fetching progress:', error);
            updateDebugInfo({ error: error.message });
        });
}

function getStatusColor(status) {
    const colors = {
        'pending': '#FF9800',
        'processing': '#2196F3',
        'completed': '#4CAF50',
        'failed': '#f44336'
    };
    return colors[status] || '#999';
}

function showError(message) {
    document.getElementById('error-container').style.display = 'block';
    document.getElementById('error-message').textContent = message;
}

function hideError() {
    document.getElementById('error-container').style.display = 'none';
}

function updateDebugInfo(data) {
    const debugInfo = document.getElementById('debug-info');
    debugInfo.textContent = JSON.stringify(data, null, 2);
}

// Auto-start if in query params
window.addEventListener('load', function() {
    const params = new URLSearchParams(window.location.search);
    const videoId = params.get('videoId');
    if (videoId) {
        document.getElementById('videoId').value = videoId;
        startTracking();
    }
});
</script>

<style>
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        background: #fafafa;
        color: #333;
    }
    #video-progress-container {
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
</style>
