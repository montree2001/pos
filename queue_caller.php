<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบเรียกคิว</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * {
            font-family: 'Sarabun', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .queue-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .queue-header {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .queue-number-display {
            font-size: 4rem;
            font-weight: 700;
            margin: 20px 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }
        
        .queue-body {
            padding: 40px;
        }
        
        .queue-input {
            font-size: 2rem;
            text-align: center;
            border: 3px solid #667eea;
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 30px;
        }
        
        .call-button {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            padding: 20px 40px;
            font-size: 1.8rem;
            border-radius: 50px;
            color: white;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s ease;
            box-shadow: 0 10px 30px rgba(40, 167, 69, 0.3);
        }
        
        .call-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(40, 167, 69, 0.4);
        }
        
        .call-button:active {
            transform: translateY(0);
        }
        
        .call-button:disabled {
            background: #6c757d;
            transform: none;
            box-shadow: none;
        }
        
        .status-message {
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: center;
            font-weight: 500;
        }
        
        .status-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .status-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .status-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .controls {
            background: #f8f9fa;
            padding: 20px;
            border-top: 1px solid #dee2e6;
        }
        
        .volume-control {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .sound-wave {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            margin-left: 10px;
        }
        
        .sound-wave .bar {
            width: 4px;
            height: 20px;
            background: #28a745;
            border-radius: 2px;
            animation: wave 1s infinite ease-in-out;
        }
        
        .sound-wave .bar:nth-child(2) { animation-delay: 0.1s; }
        .sound-wave .bar:nth-child(3) { animation-delay: 0.2s; }
        .sound-wave .bar:nth-child(4) { animation-delay: 0.3s; }
        
        @keyframes wave {
            0%, 40%, 100% { transform: scaleY(0.4); }
            20% { transform: scaleY(1); }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .history {
            max-height: 200px;
            overflow-y: auto;
        }
        
        .history-item {
            display: flex;
            justify-content: between;
            align-items: center;
            padding: 10px;
            background: white;
            border-radius: 8px;
            margin-bottom: 5px;
            border-left: 4px solid #667eea;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="queue-card">
            <!-- Header -->
            <div class="queue-header">
                <h1><i class="fas fa-bullhorn"></i> ระบบเรียกคิว</h1>
                <div id="currentQueue" class="queue-number-display">-</div>
                <p class="mb-0">กดเพื่อเรียกคิวลูกค้า</p>
            </div>
            
            <!-- Body -->
            <div class="queue-body">
                <input type="number" 
                       id="queueInput" 
                       class="form-control queue-input" 
                       placeholder="ใส่หมายเลขคิว" 
                       min="1" 
                       max="999"
                       autocomplete="off">
                
                <button id="callBtn" class="btn call-button">
                    <i class="fas fa-volume-up"></i> 
                    เรียกคิว
                    <div id="soundWave" class="sound-wave" style="display: none;">
                        <div class="bar"></div>
                        <div class="bar"></div>
                        <div class="bar"></div>
                        <div class="bar"></div>
                    </div>
                </button>
                
                <div id="statusMsg" class="status-message" style="display: none;"></div>
            </div>
            
            <!-- Controls -->
            <div class="controls">
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-cog"></i> การตั้งค่า</h6>
                        
                        <div class="volume-control">
                            <i class="fas fa-volume-down"></i>
                            <input type="range" id="volumeSlider" class="form-range" min="0" max="100" value="80">
                            <i class="fas fa-volume-up"></i>
                            <span id="volumeDisplay" class="badge bg-primary">80%</span>
                        </div>
                        
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="enableSound" checked>
                            <label class="form-check-label" for="enableSound">เปิดเสียง</label>
                        </div>
                        
                        <button id="testBtn" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-play"></i> ทดสอบ
                        </button>
                    </div>
                    
                    <div class="col-md-6">
                        <h6><i class="fas fa-history"></i> ประวัติ</h6>
                        <div id="history" class="history">
                            <div class="text-muted text-center">ยังไม่มีการเรียกคิว</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        class SimpleQueueCaller {
            constructor() {
                this.volume = 0.8;
                this.enabled = true;
                this.isPlaying = false;
                this.history = [];
                this.currentQueue = null;
                
                // Initialize
                this.initAudio();
                this.bindEvents();
                this.loadSettings();
                this.updateUI();
            }
            
            initAudio() {
                // Check if browser supports Speech Synthesis
                if ('speechSynthesis' in window) {
                    this.speech = window.speechSynthesis;
                    this.loadVoices();
                } else {
                    console.warn('Speech Synthesis not supported');
                    this.speech = null;
                }
                
                // Create beep sound using Web Audio API
                this.createBeepSound();
            }
            
            createBeepSound() {
                try {
                    this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
                    console.log('Audio context created');
                } catch (e) {
                    console.warn('Web Audio API not supported:', e);
                    this.audioContext = null;
                }
            }
            
            loadVoices() {
                const loadVoicesWhenReady = () => {
                    const voices = this.speech.getVoices();
                    
                    // Find Thai voice
                    this.voice = voices.find(v => v.lang.startsWith('th')) || 
                                voices.find(v => v.lang.startsWith('en')) || 
                                voices[0];
                    
                    console.log('Voice loaded:', this.voice?.name || 'No voice');
                };
                
                if (this.speech.getVoices().length > 0) {
                    loadVoicesWhenReady();
                } else {
                    this.speech.addEventListener('voiceschanged', loadVoicesWhenReady);
                }
            }
            
            playBeep() {
                return new Promise((resolve) => {
                    if (!this.enabled || !this.audioContext) {
                        resolve();
                        return;
                    }
                    
                    try {
                        // Create oscillator for beep sound
                        const oscillator = this.audioContext.createOscillator();
                        const gainNode = this.audioContext.createGain();
                        
                        oscillator.connect(gainNode);
                        gainNode.connect(this.audioContext.destination);
                        
                        oscillator.frequency.setValueAtTime(800, this.audioContext.currentTime);
                        oscillator.type = 'sine';
                        
                        gainNode.gain.setValueAtTime(0, this.audioContext.currentTime);
                        gainNode.gain.linearRampToValueAtTime(this.volume * 0.3, this.audioContext.currentTime + 0.1);
                        gainNode.gain.linearRampToValueAtTime(0, this.audioContext.currentTime + 0.3);
                        
                        oscillator.start(this.audioContext.currentTime);
                        oscillator.stop(this.audioContext.currentTime + 0.3);
                        
                        oscillator.onended = () => resolve();
                        
                        console.log('Playing beep sound');
                    } catch (e) {
                        console.warn('Could not play beep:', e);
                        resolve();
                    }
                });
            }
            
            speak(text) {
                return new Promise((resolve) => {
                    if (!this.enabled || !this.speech) {
                        resolve();
                        return;
                    }
                    
                    try {
                        this.speech.cancel(); // Stop any current speech
                        
                        const utterance = new SpeechSynthesisUtterance(text);
                        utterance.voice = this.voice;
                        utterance.volume = this.volume;
                        utterance.rate = 0.9;
                        utterance.pitch = 1.0;
                        utterance.lang = 'th-TH';
                        
                        utterance.onend = () => {
                            console.log('Speech finished');
                            resolve();
                        };
                        
                        utterance.onerror = (e) => {
                            console.warn('Speech error:', e);
                            resolve();
                        };
                        
                        this.speech.speak(utterance);
                        console.log('Speaking:', text);
                    } catch (e) {
                        console.warn('Could not speak:', e);
                        resolve();
                    }
                });
            }
            
            async callQueue() {
                const queueNumber = document.getElementById('queueInput').value.trim();
                
                if (!queueNumber) {
                    this.showMessage('กรุณาใส่หมายเลขคิว', 'error');
                    return;
                }
                
                if (this.isPlaying) {
                    this.showMessage('กำลังเรียกคิวอยู่ รอสักครู่', 'info');
                    return;
                }
                
                try {
                    this.isPlaying = true;
                    this.currentQueue = queueNumber;
                    
                    // Update UI
                    this.updateCurrentQueue(queueNumber);
                    this.showSoundWave(true);
                    this.disableButton(true);
                    
                    // Play beep first
                    console.log('Starting queue call for:', queueNumber);
                    await this.playBeep();
                    
                    // Wait a bit, then speak
                    await new Promise(resolve => setTimeout(resolve, 500));
                    
                    const message = `คิวที่ ${queueNumber} กรุณามารับออเดอร์ที่เคาน์เตอร์ครับ`;
                    await this.speak(message);
                    
                    // Add to history
                    this.addToHistory(queueNumber);
                    
                    // Send to API
                    this.sendToAPI(queueNumber);
                    
                    this.showMessage(`เรียกคิว ${queueNumber} เรียบร้อยแล้ว`, 'success');
                    
                    // Clear input
                    document.getElementById('queueInput').value = '';
                    
                } catch (error) {
                    console.error('Error calling queue:', error);
                    this.showMessage('เกิดข้อผิดพลาด: ' + error.message, 'error');
                } finally {
                    setTimeout(() => {
                        this.isPlaying = false;
                        this.showSoundWave(false);
                        this.disableButton(false);
                    }, 1000);
                }
            }
            
            async testSound() {
                if (this.isPlaying) return;
                
                this.showMessage('ทดสอบเสียง...', 'info');
                
                try {
                    this.isPlaying = true;
                    this.showSoundWave(true);
                    this.disableButton(true);
                    
                    await this.playBeep();
                    await new Promise(resolve => setTimeout(resolve, 500));
                    await this.speak('ทดสอบระบบเสียง คิวที่ 1 กรุณามารับออเดอร์ที่เคาน์เตอร์ครับ');
                    
                    this.showMessage('ทดสอบเสียงเรียบร้อย', 'success');
                } catch (error) {
                    this.showMessage('ไม่สามารถทดสอบเสียงได้', 'error');
                } finally {
                    setTimeout(() => {
                        this.isPlaying = false;
                        this.showSoundWave(false);
                        this.disableButton(false);
                    }, 1000);
                }
            }
            
            sendToAPI(queueNumber) {
                fetch('api/simple_voice_queue.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'call_queue',
                        queue_number: queueNumber
                    })
                })
                .then(response => response.json())
                .then(data => {
                    console.log('API response:', data);
                    if (data.success) {
                        console.log('✅ API สำเร็จ:', data.message);
                    }
                })
                .catch(error => {
                    console.warn('API error:', error);
                });
            }
            
            updateCurrentQueue(queueNumber) {
                const display = document.getElementById('currentQueue');
                display.textContent = queueNumber;
                display.classList.add('pulse');
                setTimeout(() => display.classList.remove('pulse'), 2000);
            }
            
            showSoundWave(show) {
                const wave = document.getElementById('soundWave');
                wave.style.display = show ? 'inline-flex' : 'none';
            }
            
            disableButton(disabled) {
                const btn = document.getElementById('callBtn');
                btn.disabled = disabled;
                btn.innerHTML = disabled ? 
                    '<i class="fas fa-spinner fa-spin"></i> กำลังเรียก...' :
                    '<i class="fas fa-volume-up"></i> เรียกคิว';
            }
            
            showMessage(text, type) {
                const msg = document.getElementById('statusMsg');
                msg.textContent = text;
                msg.className = `status-message status-${type}`;
                msg.style.display = 'block';
                
                setTimeout(() => {
                    msg.style.display = 'none';
                }, 3000);
            }
            
            addToHistory(queueNumber) {
                const item = {
                    queue: queueNumber,
                    time: new Date()
                };
                
                this.history.unshift(item);
                if (this.history.length > 5) {
                    this.history = this.history.slice(0, 5);
                }
                
                this.updateHistory();
                this.saveSettings();
            }
            
            updateHistory() {
                const container = document.getElementById('history');
                
                if (this.history.length === 0) {
                    container.innerHTML = '<div class="text-muted text-center">ยังไม่มีการเรียกคิว</div>';
                    return;
                }
                
                let html = '';
                this.history.forEach(item => {
                    const timeStr = item.time.toLocaleTimeString('th-TH', {
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    
                    html += `
                        <div class="history-item">
                            <span>คิว ${item.queue}</span>
                            <small class="text-muted">${timeStr}</small>
                        </div>
                    `;
                });
                
                container.innerHTML = html;
            }
            
            setVolume(value) {
                this.volume = value / 100;
                this.updateUI();
                this.saveSettings();
            }
            
            toggleSound(enabled) {
                this.enabled = enabled;
                this.saveSettings();
            }
            
            updateUI() {
                document.getElementById('volumeSlider').value = this.volume * 100;
                document.getElementById('volumeDisplay').textContent = Math.round(this.volume * 100) + '%';
                document.getElementById('enableSound').checked = this.enabled;
            }
            
            saveSettings() {
                const settings = {
                    volume: this.volume,
                    enabled: this.enabled,
                    history: this.history.map(item => ({
                        queue: item.queue,
                        time: item.time.getTime()
                    }))
                };
                
                localStorage.setItem('queueCallerSettings', JSON.stringify(settings));
            }
            
            loadSettings() {
                try {
                    const saved = localStorage.getItem('queueCallerSettings');
                    if (saved) {
                        const settings = JSON.parse(saved);
                        this.volume = settings.volume || 0.8;
                        this.enabled = settings.enabled !== undefined ? settings.enabled : true;
                        
                        if (settings.history) {
                            this.history = settings.history.map(item => ({
                                queue: item.queue,
                                time: new Date(item.time)
                            }));
                            this.updateHistory();
                        }
                    }
                } catch (e) {
                    console.warn('Could not load settings:', e);
                }
            }
            
            bindEvents() {
                // Call queue button
                document.getElementById('callBtn').addEventListener('click', () => {
                    this.callQueue();
                });
                
                // Enter key in input
                document.getElementById('queueInput').addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        this.callQueue();
                    }
                });
                
                // Volume slider
                document.getElementById('volumeSlider').addEventListener('input', (e) => {
                    this.setVolume(e.target.value);
                });
                
                // Sound toggle
                document.getElementById('enableSound').addEventListener('change', (e) => {
                    this.toggleSound(e.target.checked);
                });
                
                // Test button
                document.getElementById('testBtn').addEventListener('click', () => {
                    this.testSound();
                });
                
                // Keyboard shortcuts
                document.addEventListener('keydown', (e) => {
                    if (e.target.tagName !== 'INPUT') {
                        if (e.code === 'Space') {
                            e.preventDefault();
                            this.callQueue();
                        }
                        if (e.key === 'Escape') {
                            if (this.speech) this.speech.cancel();
                            this.isPlaying = false;
                            this.showSoundWave(false);
                            this.disableButton(false);
                        }
                    }
                });
            }
        }
        
        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', () => {
            console.log('🚀 DOM loaded, initializing...');
            
            // Initialize immediately
            window.queueCaller = new SimpleQueueCaller();
            
            // Force audio context activation on any user interaction
            const activateAudio = () => {
                if (window.queueCaller && window.queueCaller.audioContext) {
                    if (window.queueCaller.audioContext.state === 'suspended') {
                        window.queueCaller.audioContext.resume().then(() => {
                            console.log('✅ Audio context resumed');
                        });
                    }
                }
            };
            
            document.body.addEventListener('click', activateAudio);
            document.body.addEventListener('keydown', activateAudio);
            document.body.addEventListener('touchstart', activateAudio);
        });
    </script>
</body>
</html>