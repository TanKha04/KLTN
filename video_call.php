<?php
require_once 'config.php';
require_login();

$callWith = isset($_GET['with']) ? (int)$_GET['with'] : 0;
$answerCallId = isset($_GET['answer']) ? (int)$_GET['answer'] : 0;
$currentUserId = $_SESSION['user_id'];

if ($callWith <= 0 || $callWith == $currentUserId) {
    header('Location: index.php');
    exit;
}

// Lấy thông tin người được gọi/người gọi
$stmt = $pdo->prepare('SELECT id, name, avatar, phone FROM users WHERE id = ?');
$stmt->execute([$callWith]);
$otherUser = $stmt->fetch();

if (!$otherUser) {
    header('Location: index.php');
    exit;
}

// Lấy thông tin người hiện tại
$stmt = $pdo->prepare('SELECT id, name, avatar FROM users WHERE id = ?');
$stmt->execute([$currentUserId]);
$currentUser = $stmt->fetch();

// Kiểm tra nếu đang trả lời cuộc gọi
$isAnswering = $answerCallId > 0;

require_once 'header.php';
?>

<style>
.call-page {
    min-height: 100vh;
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    margin: -1.5rem -0.75rem;
    padding: 0;
    display: flex;
    flex-direction: column;
}

.call-container {
    flex: 1;
    display: flex;
    flex-direction: column;
    max-width: 1200px;
    margin: 0 auto;
    width: 100%;
    padding: 1rem;
}

.call-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    color: #fff;
}

.call-back {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #94a3b8;
    text-decoration: none;
    font-weight: 500;
    transition: color 0.3s;
}

.call-back:hover { color: #fff; }

.call-status {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: rgba(255,255,255,0.1);
    border-radius: 20px;
    font-size: 0.9rem;
}

.call-status.connecting { color: #fbbf24; }
.call-status.connected { color: #34d399; }
.call-status.ended { color: #f87171; }

.video-container {
    flex: 1;
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
    padding: 1rem;
    position: relative;
}

.video-wrapper {
    position: relative;
    background: #334155;
    border-radius: 20px;
    overflow: hidden;
    aspect-ratio: 16/9;
    display: flex;
    align-items: center;
    justify-content: center;
}

.video-wrapper video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.video-wrapper.local {
    position: absolute;
    bottom: 1rem;
    right: 1rem;
    width: 200px;
    aspect-ratio: 4/3;
    z-index: 10;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    cursor: move;
}

.video-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
    text-align: center;
    padding: 2rem;
}

.video-placeholder .avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    color: #fff;
    font-weight: 700;
    margin-bottom: 1rem;
}

.video-placeholder .avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

.video-placeholder .name {
    font-size: 1.5rem;
    font-weight: 600;
    color: #fff;
    margin-bottom: 0.5rem;
}

.call-controls {
    display: flex;
    justify-content: center;
    gap: 1rem;
    padding: 2rem;
    background: rgba(0,0,0,0.3);
}

.call-btn {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    cursor: pointer;
    transition: all 0.3s ease;
}

.call-btn:hover { transform: scale(1.1); }
.call-btn.mute { background: #475569; color: #fff; }
.call-btn.mute.active { background: #ef4444; }
.call-btn.video-toggle { background: #475569; color: #fff; }
.call-btn.video-toggle.active { background: #ef4444; }
.call-btn.end-call { background: #ef4444; color: #fff; width: 70px; height: 70px; }
.call-btn.end-call:hover { background: #dc2626; }
.call-btn.start-call { background: #10b981; color: #fff; width: 70px; height: 70px; }
.call-btn.start-call:hover { background: #059669; }

.call-timer {
    font-size: 1.25rem;
    font-weight: 600;
    color: #fff;
    font-family: monospace;
}

.waiting-screen {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #fff;
    text-align: center;
    padding: 2rem;
}

.waiting-avatar {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 4rem;
    color: #fff;
    font-weight: 700;
    margin-bottom: 1.5rem;
    animation: pulse 2s infinite;
}

.waiting-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

@keyframes pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.5); }
    50% { box-shadow: 0 0 0 30px rgba(59, 130, 246, 0); }
}

.waiting-name { font-size: 2rem; font-weight: 700; margin-bottom: 0.5rem; }
.waiting-status { font-size: 1.1rem; color: #94a3b8; margin-bottom: 2rem; }
.waiting-actions { display: flex; gap: 1.5rem; }

/* Incoming call overlay */
.incoming-call-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.9);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    color: #fff;
}

.incoming-call-overlay .avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    font-weight: 700;
    margin-bottom: 1rem;
    animation: pulse 1.5s infinite;
}

.incoming-call-overlay .caller-name {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.incoming-call-overlay .call-text {
    color: #94a3b8;
    margin-bottom: 2rem;
}

.incoming-call-overlay .actions {
    display: flex;
    gap: 2rem;
}

.incoming-call-overlay .btn-answer {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background: #10b981;
    color: #fff;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    animation: pulse-green 1.5s infinite;
}

.incoming-call-overlay .btn-reject {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background: #ef4444;
    color: #fff;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
}

@keyframes pulse-green {
    0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.5); }
    50% { box-shadow: 0 0 0 20px rgba(16, 185, 129, 0); }
}

@media (max-width: 767px) {
    .video-wrapper.local { width: 120px; }
    .call-btn { width: 50px; height: 50px; font-size: 1.25rem; }
    .call-btn.end-call, .call-btn.start-call { width: 60px; height: 60px; }
}
</style>

<div class="call-page">
    <div class="call-container">
        <div class="call-header">
            <a href="javascript:endCall()" class="call-back">
                <i class="bi bi-arrow-left"></i> Quay lại
            </a>
            <div class="call-status connecting" id="callStatus">
                <i class="bi bi-circle-fill" style="font-size: 0.5rem;"></i>
                <span id="statusText">Chờ kết nối...</span>
            </div>
            <div class="call-timer" id="callTimer" style="display: none;">00:00</div>
        </div>

        <div class="waiting-screen" id="waitingScreen">
            <div class="waiting-avatar">
                <?php if (!empty($otherUser['avatar']) && file_exists('uploads/' . $otherUser['avatar'])): ?>
                    <img src="uploads/<?php echo htmlspecialchars($otherUser['avatar']); ?>" alt="">
                <?php else: ?>
                    <?php echo strtoupper(substr($otherUser['name'], 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div class="waiting-name"><?php echo htmlspecialchars($otherUser['name']); ?></div>
            <div class="waiting-status" id="waitingStatus">Nhấn nút để bắt đầu cuộc gọi video</div>
            <div class="waiting-actions">
                <button class="call-btn start-call" onclick="initiateCall()" title="Bắt đầu gọi video">
                    <i class="bi bi-camera-video-fill"></i>
                </button>
                <button class="call-btn end-call" onclick="endCall()" title="Hủy">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>

        <div class="video-container" id="videoContainer" style="display: none;">
            <div class="video-wrapper remote" id="remoteVideoWrapper">
                <video id="remoteVideo" autoplay playsinline webkit-playsinline></video>
                <div class="video-placeholder" id="remotePlaceholder">
                    <div class="avatar">
                        <?php if (!empty($otherUser['avatar']) && file_exists('uploads/' . $otherUser['avatar'])): ?>
                            <img src="uploads/<?php echo htmlspecialchars($otherUser['avatar']); ?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($otherUser['name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="name"><?php echo htmlspecialchars($otherUser['name']); ?></div>
                    <div class="status" id="remoteStatus">Đang chờ kết nối...</div>
                </div>
            </div>
            <div class="video-wrapper local" id="localVideoWrapper">
                <video id="localVideo" autoplay playsinline muted></video>
            </div>
        </div>

        <div class="call-controls" id="callControls" style="display: none;">
            <button class="call-btn mute" id="muteBtn" onclick="toggleMute()" title="Tắt/Bật mic">
                <i class="bi bi-mic-fill"></i>
            </button>
            <button class="call-btn video-toggle" id="videoBtn" onclick="toggleVideo()" title="Tắt/Bật camera">
                <i class="bi bi-camera-video-fill"></i>
            </button>
            <button class="call-btn end-call" onclick="endCall()" title="Kết thúc cuộc gọi">
                <i class="bi bi-telephone-x-fill"></i>
            </button>
        </div>
    </div>
</div>

<!-- Incoming call notification sound -->
<audio id="ringtone" loop>
    <source src="https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3" type="audio/mpeg">
</audio>

<script>
const currentUserId = <?php echo $currentUserId; ?>;
const otherUserId = <?php echo $callWith; ?>;
const otherUserName = "<?php echo addslashes($otherUser['name']); ?>";
const isAnswering = <?php echo $isAnswering ? 'true' : 'false'; ?>;
const answerCallId = <?php echo $answerCallId ?: 'null'; ?>;

let localStream = null;
let remoteStream = null;
let peerConnection = null;
let callId = answerCallId;
let isMuted = false;
let isVideoOff = false;
let callStartTime = null;
let timerInterval = null;
let pollingInterval = null;
let lastSignalId = 0;

// WebRTC configuration với STUN/TURN servers
const rtcConfig = {
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
        { urls: 'stun:stun2.l.google.com:19302' },
        { urls: 'stun:stun3.l.google.com:19302' },
        { urls: 'stun:stun4.l.google.com:19302' },
        // TURN server miễn phí (có thể chậm nhưng hoạt động)
        {
            urls: 'turn:openrelay.metered.ca:80',
            username: 'openrelayproject',
            credential: 'openrelayproject'
        },
        {
            urls: 'turn:openrelay.metered.ca:443',
            username: 'openrelayproject',
            credential: 'openrelayproject'
        }
    ],
    iceCandidatePoolSize: 10
};

async function initiateCall() {
    try {
        updateWaitingStatus('Đang truy cập camera...');
        
        // Lấy media stream
        localStream = await navigator.mediaDevices.getUserMedia({
            video: true,
            audio: true
        });
        
        // Hiển thị local video
        document.getElementById('localVideo').srcObject = localStream;
        
        updateWaitingStatus('Đang gọi ' + otherUserName + '...');
        
        // Gửi yêu cầu gọi
        const response = await fetch('call_signaling.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=initiate_call&callee_id=' + otherUserId
        });
        const data = await response.json();
        
        if (data.success) {
            callId = data.call_id;
            
            // Chuyển sang màn hình video
            showVideoScreen();
            updateStatus('connecting', 'Đang chờ ' + otherUserName + ' trả lời...');
            
            // Bắt đầu polling để kiểm tra trạng thái
            startPolling();
        } else {
            alert('Không thể bắt đầu cuộc gọi: ' + (data.error || 'Lỗi không xác định'));
        }
        
    } catch (error) {
        console.error('Error:', error);
        handleMediaError(error);
    }
}

function showVideoScreen() {
    document.getElementById('waitingScreen').style.display = 'none';
    document.getElementById('videoContainer').style.display = 'grid';
    document.getElementById('callControls').style.display = 'flex';
}

function startPolling() {
    pollingInterval = setInterval(async () => {
        try {
            // Kiểm tra trạng thái cuộc gọi
            const statusRes = await fetch('call_signaling.php?action=check_status&call_id=' + callId);
            const statusData = await statusRes.json();
            console.log('Call status:', statusData.status);
            
            if (statusData.status === 'answered') {
                // Người kia đã trả lời, tạo peer connection và gửi offer
                if (!peerConnection) {
                    console.log('Creating peer connection and sending offer...');
                    await createPeerConnection();
                    await createAndSendOffer();
                }
            } else if (statusData.status === 'rejected') {
                updateStatus('ended', 'Cuộc gọi bị từ chối');
                setTimeout(endCall, 2000);
                return;
            } else if (statusData.status === 'ended') {
                updateStatus('ended', 'Cuộc gọi đã kết thúc');
                setTimeout(endCall, 2000);
                return;
            }
            
            // Lấy signals mới
            const signalsRes = await fetch('call_signaling.php?action=get_signals&call_id=' + callId + '&last_id=' + lastSignalId);
            const signalsData = await signalsRes.json();
            console.log('Received signals:', signalsData.signals.length);
            
            for (const signal of signalsData.signals) {
                lastSignalId = Math.max(lastSignalId, signal.id);
                await handleSignal(signal);
            }
        } catch (error) {
            console.error('Polling error:', error);
        }
        
    }, 1000);
}

async function createPeerConnection() {
    peerConnection = new RTCPeerConnection(rtcConfig);
    
    const remoteVideo = document.getElementById('remoteVideo');
    
    // Thêm local tracks
    localStream.getTracks().forEach(track => {
        console.log('Adding local track:', track.kind);
        peerConnection.addTrack(track, localStream);
    });
    
    // Xử lý remote tracks - gán trực tiếp stream từ event
    peerConnection.ontrack = (event) => {
        console.log('Received remote track:', event.track.kind, event.streams.length);
        
        // Gán stream trực tiếp thay vì tạo MediaStream mới
        if (event.streams && event.streams[0]) {
            console.log('Setting remote stream directly');
            remoteVideo.srcObject = event.streams[0];
            remoteStream = event.streams[0];
        } else {
            // Fallback: tạo stream mới nếu không có
            if (!remoteStream) {
                remoteStream = new MediaStream();
                remoteVideo.srcObject = remoteStream;
            }
            remoteStream.addTrack(event.track);
        }
        
        document.getElementById('remotePlaceholder').style.display = 'none';
        
        // Đảm bảo video play (quan trọng cho mobile)
        remoteVideo.play().then(() => {
            console.log('Remote video playing');
        }).catch(e => {
            console.log('Video play error:', e);
            // Thử play lại sau 1 giây
            setTimeout(() => remoteVideo.play().catch(() => {}), 1000);
        });
        
        if (!callStartTime) {
            updateStatus('connected', 'Đã kết nối');
            startTimer();
        }
    };
    
    // Xử lý ICE candidates
    peerConnection.onicecandidate = async (event) => {
        if (event.candidate) {
            console.log('Sending ICE candidate');
            await sendSignal('ice_candidate', JSON.stringify(event.candidate));
        }
    };
    
    // ICE connection state
    peerConnection.oniceconnectionstatechange = () => {
        console.log('ICE connection state:', peerConnection.iceConnectionState);
        if (peerConnection.iceConnectionState === 'connected' || 
            peerConnection.iceConnectionState === 'completed') {
            updateStatus('connected', 'Đã kết nối');
        }
    };
    
    peerConnection.onconnectionstatechange = () => {
        console.log('Connection state:', peerConnection.connectionState);
        if (peerConnection.connectionState === 'connected') {
            updateStatus('connected', 'Đã kết nối');
            document.getElementById('remotePlaceholder').style.display = 'none';
        } else if (peerConnection.connectionState === 'disconnected' || 
            peerConnection.connectionState === 'failed') {
            updateStatus('ended', 'Mất kết nối');
        }
    };
    
    // Debug: ICE gathering state
    peerConnection.onicegatheringstatechange = () => {
        console.log('ICE gathering state:', peerConnection.iceGatheringState);
    };
}

async function createAndSendOffer() {
    const offer = await peerConnection.createOffer();
    await peerConnection.setLocalDescription(offer);
    await sendSignal('offer', JSON.stringify(offer));
    console.log('Sent offer');
}

async function handleSignal(signal) {
    console.log('Handling signal:', signal.signal_type);
    
    try {
        if (signal.signal_type === 'offer') {
            if (!peerConnection) {
                await createPeerConnection();
            }
            const offer = JSON.parse(signal.signal_data);
            console.log('Setting remote description (offer)...');
            await peerConnection.setRemoteDescription(new RTCSessionDescription(offer));
            
            console.log('Creating answer...');
            const answer = await peerConnection.createAnswer();
            await peerConnection.setLocalDescription(answer);
            await sendSignal('answer', JSON.stringify(answer));
            console.log('Sent answer');
            
        } else if (signal.signal_type === 'answer') {
            if (peerConnection && peerConnection.signalingState !== 'stable') {
                const answer = JSON.parse(signal.signal_data);
                await peerConnection.setRemoteDescription(new RTCSessionDescription(answer));
                console.log('Set remote description (answer)');
            }
            
        } else if (signal.signal_type === 'ice_candidate') {
            if (peerConnection && peerConnection.remoteDescription) {
                const candidate = JSON.parse(signal.signal_data);
                await peerConnection.addIceCandidate(new RTCIceCandidate(candidate));
                console.log('Added ICE candidate');
            }
        }
    } catch (error) {
        console.error('Error handling signal:', error);
    }
}

async function sendSignal(type, data) {
    await fetch('call_signaling.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=send_signal&call_id=' + callId + '&signal_type=' + type + '&signal_data=' + encodeURIComponent(data)
    });
}

function toggleMute() {
    if (!localStream) return;
    const audioTrack = localStream.getAudioTracks()[0];
    if (audioTrack) {
        isMuted = !isMuted;
        audioTrack.enabled = !isMuted;
        const btn = document.getElementById('muteBtn');
        btn.classList.toggle('active', isMuted);
        btn.innerHTML = isMuted ? '<i class="bi bi-mic-mute-fill"></i>' : '<i class="bi bi-mic-fill"></i>';
    }
}

function toggleVideo() {
    if (!localStream) return;
    const videoTrack = localStream.getVideoTracks()[0];
    if (videoTrack) {
        isVideoOff = !isVideoOff;
        videoTrack.enabled = !isVideoOff;
        const btn = document.getElementById('videoBtn');
        btn.classList.toggle('active', isVideoOff);
        btn.innerHTML = isVideoOff ? '<i class="bi bi-camera-video-off-fill"></i>' : '<i class="bi bi-camera-video-fill"></i>';
    }
}

async function endCall() {
    // Dừng polling
    if (pollingInterval) {
        clearInterval(pollingInterval);
    }
    
    // Gửi signal kết thúc
    if (callId) {
        await fetch('call_signaling.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=end_call&call_id=' + callId
        });
    }
    
    // Đóng peer connection
    if (peerConnection) {
        peerConnection.close();
        peerConnection = null;
    }
    
    // Dừng media streams
    if (localStream) {
        localStream.getTracks().forEach(track => track.stop());
        localStream = null;
    }
    
    // Dừng timer
    if (timerInterval) {
        clearInterval(timerInterval);
    }
    
    // Quay lại trang trước
    history.back();
}

function updateStatus(status, text) {
    const statusEl = document.getElementById('callStatus');
    const statusText = document.getElementById('statusText');
    statusEl.className = 'call-status ' + status;
    statusText.textContent = text;
}

function updateWaitingStatus(text) {
    document.getElementById('waitingStatus').textContent = text;
}

function startTimer() {
    callStartTime = Date.now();
    document.getElementById('callTimer').style.display = 'block';
    timerInterval = setInterval(() => {
        const elapsed = Math.floor((Date.now() - callStartTime) / 1000);
        const minutes = Math.floor(elapsed / 60).toString().padStart(2, '0');
        const seconds = (elapsed % 60).toString().padStart(2, '0');
        document.getElementById('callTimer').textContent = minutes + ':' + seconds;
    }, 1000);
}

function handleMediaError(error) {
    let msg = 'Không thể truy cập camera/microphone.';
    if (error.name === 'NotAllowedError') {
        msg = 'Bạn cần cho phép truy cập camera và microphone.';
    } else if (error.name === 'NotFoundError') {
        msg = 'Không tìm thấy camera hoặc microphone.';
    }
    updateWaitingStatus(msg);
    alert(msg);
}

// Cleanup
window.addEventListener('beforeunload', () => {
    if (localStream) {
        localStream.getTracks().forEach(track => track.stop());
    }
    if (peerConnection) {
        peerConnection.close();
    }
});

// Tự động bắt đầu nếu đang trả lời cuộc gọi
document.addEventListener('DOMContentLoaded', async () => {
    if (isAnswering && answerCallId) {
        try {
            updateWaitingStatus('Đang kết nối với ' + otherUserName + '...');
            
            // Lấy media stream
            localStream = await navigator.mediaDevices.getUserMedia({
                video: true,
                audio: true
            });
            
            // Hiển thị local video
            document.getElementById('localVideo').srcObject = localStream;
            
            // Chuyển sang màn hình video
            showVideoScreen();
            updateStatus('connecting', 'Đang kết nối...');
            
            // GỬI SIGNAL "ANSWERED" ĐỂ NGƯỜI GỌI BIẾT
            await fetch('call_signaling.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=answer_call&call_id=' + answerCallId
            });
            
            // Tạo peer connection và bắt đầu polling
            await createPeerConnection();
            startPolling();
            
        } catch (error) {
            console.error('Error:', error);
            handleMediaError(error);
        }
    }
});
</script>

<?php require_once 'footer.php'; ?>
