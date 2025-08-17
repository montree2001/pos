/**
 * Audio Utilities for POS System
 * Contains audio generation and playback functions
 */

class AudioUtils {
    constructor() {
        this.audioContext = null;
        this.isSupported = false;
        this.init();
    }
    
    init() {
        try {
            this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            this.isSupported = true;
        } catch (e) {
            console.warn('Web Audio API not supported:', e);
            this.isSupported = false;
        }
    }
    
    // สร้างเสียง beep
    createBeep(frequency = 800, duration = 200, volume = 0.3) {
        if (!this.isSupported) return null;
        
        return new Promise((resolve) => {
            const oscillator = this.audioContext.createOscillator();
            const gainNode = this.audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(this.audioContext.destination);
            
            oscillator.frequency.setValueAtTime(frequency, this.audioContext.currentTime);
            oscillator.type = 'sine';
            
            gainNode.gain.setValueAtTime(0, this.audioContext.currentTime);
            gainNode.gain.linearRampToValueAtTime(volume, this.audioContext.currentTime + 0.01);
            gainNode.gain.exponentialRampToValueAtTime(0.01, this.audioContext.currentTime + duration / 1000);
            
            oscillator.start(this.audioContext.currentTime);
            oscillator.stop(this.audioContext.currentTime + duration / 1000);
            
            oscillator.onended = () => resolve();
        });
    }
    
    // เล่นเสียงแจ้งเตือนสำหรับคิว
    async playQueueNotification() {
        if (!this.isSupported) {
            // Fallback สำหรับเบราว์เซอร์ที่ไม่รองรับ Web Audio API
            this.playFallbackSound();
            return;
        }
        
        try {
            // เล่นเสียง beep 2 ครั้ง
            await this.createBeep(800, 150, 0.4);
            await new Promise(resolve => setTimeout(resolve, 100));
            await this.createBeep(1000, 200, 0.4);
        } catch (error) {
            console.error('Error playing notification sound:', error);
            this.playFallbackSound();
        }
    }
    
    // เสียง success
    async playSuccessSound() {
        if (!this.isSupported) return;
        
        try {
            await this.createBeep(600, 100, 0.3);
            await new Promise(resolve => setTimeout(resolve, 50));
            await this.createBeep(800, 100, 0.3);
            await new Promise(resolve => setTimeout(resolve, 50));
            await this.createBeep(1000, 150, 0.3);
        } catch (error) {
            console.error('Error playing success sound:', error);
        }
    }
    
    // เสียง error
    async playErrorSound() {
        if (!this.isSupported) return;
        
        try {
            await this.createBeep(300, 200, 0.4);
            await new Promise(resolve => setTimeout(resolve, 100));
            await this.createBeep(200, 300, 0.4);
        } catch (error) {
            console.error('Error playing error sound:', error);
        }
    }
    
    // Fallback sound ใช้ HTML5 Audio
    playFallbackSound() {
        // สร้าง data URL สำหรับเสียง beep
        const audioData = 'data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSsFJHfH8N2QQAoUXrTp66hVFApGn+DyvmceAyuGze7acSZnVpbJy8ZmFkLx5ZG+Aw==';
        try {
            const audio = new Audio(audioData);
            audio.volume = 0.5;
            audio.play().catch(e => console.log('Could not play fallback sound:', e));
        } catch (error) {
            console.error('Error playing fallback sound:', error);
        }
    }
    
    // ตรวจสอบว่าสามารถเล่นเสียงได้หรือไม่
    async requestAudioPermission() {
        if (!this.isSupported) return false;
        
        try {
            if (this.audioContext.state === 'suspended') {
                await this.audioContext.resume();
            }
            return true;
        } catch (error) {
            console.error('Error requesting audio permission:', error);
            return false;
        }
    }
}

// สร้าง instance สำหรับใช้งาน
const audioUtils = new AudioUtils();

// Export สำหรับใช้งานในไฟล์อื่น
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AudioUtils;
}