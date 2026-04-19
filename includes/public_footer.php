<!-- Footer -->
<footer class="w-full py-12 px-8 bg-[#f0f4f9] dark:bg-[#171c20] mt-auto">
    <div class="flex flex-col md:flex-row justify-between items-center gap-6 max-w-[1440px] mx-auto">
        <div class="flex flex-col gap-2 text-center md:text-left">
            <span class="text-lg font-semibold text-[#424750] dark:text-[#f0f4f9]">Smart Attendance</span>
            <p class="font-inter text-xs font-normal leading-relaxed text-[#424750] dark:text-[#c2c7d1] opacity-80">
                Created for Cyber Security Department.
            </p>
        </div>
        <div class="flex gap-8 items-center">
            <a class="font-inter text-xs font-normal leading-relaxed text-[#424750] dark:text-[#c2c7d1] opacity-80 hover:opacity-100 hover:underline decoration-[#00457b] underline-offset-4 transition-all" href="rules.php">Rules</a>
            <a class="font-inter text-xs font-normal leading-relaxed text-[#424750] dark:text-[#c2c7d1] opacity-80 hover:opacity-100 hover:underline decoration-[#00457b] underline-offset-4 transition-all" href="support.php">Technical Support</a>
        </div>
    </div>
</footer>

<script>
// Header Countdown Timer Logic
const countdownEl = document.getElementById('headerCountdown');
let currentEndTime = 0;

function showTimeAdjustmentNotice(minutesStr, isReduced) {
    const toast = document.createElement('div');
    const sign = isReduced ? '-' : '+';
    const action = isReduced ? 'reduced by' : 'extended by';
    const colorClass = isReduced ? 'text-error' : 'text-primary';
    const bgClass = isReduced ? 'bg-error-container/10' : 'bg-surface-container-lowest';
    const borderClass = isReduced ? 'border-error/20' : 'border-outline-variant/20';
    const icon = isReduced ? 'timer_off' : 'timer';
    
    toast.innerHTML = `
        <div class="flex items-center gap-3 ${bgClass} border ${borderClass} px-5 py-3 rounded-lg shadow-xl backdrop-blur-md">
            <span class="material-symbols-outlined ${colorClass}" style="font-size: 20px; font-variation-settings: 'FILL' 1;">${icon}</span>
            <span class="text-sm font-semibold text-on-surface">Time ${action} <span class="${colorClass} font-bold tracking-tight">${sign}${minutesStr}</span></span>
        </div>
    `;
    
    toast.style.position = 'fixed';
    toast.style.bottom = '24px';
    toast.style.right = '24px';
    toast.style.zIndex = '9999';
    toast.style.transition = 'all 0.4s cubic-bezier(0.4, 0, 0.2, 1)';
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(20px)';
    
    document.body.appendChild(toast);
    
    requestAnimationFrame(() => {
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
    });
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
        setTimeout(() => toast.remove(), 400);
    }, 4500);
    
    if (countdownEl) {
        countdownEl.classList.add('scale-110');
        countdownEl.parentElement.classList.add('shadow-md');
        setTimeout(() => {
            countdownEl.classList.remove('scale-110');
            countdownEl.parentElement.classList.remove('shadow-md');
        }, 500);
    }
}

if (countdownEl) {
    const endTimeStr = countdownEl.getAttribute('data-endtime');
    currentEndTime = endTimeStr ? parseInt(endTimeStr, 10) : 0;
    
    if (currentEndTime > 0) {
        const updateTimer = () => {
            const now = Math.floor(Date.now() / 1000);
            const diff = currentEndTime - now;
            
            if (diff <= 0) {
                countdownEl.textContent = '00:00';
                countdownEl.classList.remove('text-primary');
                countdownEl.classList.add('text-error');
                countdownEl.previousElementSibling.classList.remove('text-primary', 'animate-pulse');
                countdownEl.previousElementSibling.classList.add('text-error');
                countdownEl.parentElement.classList.add('bg-error-container/10', 'border-error/20');
                
                setTimeout(() => location.reload(), 1500);
                return;
            }
            
            const m = Math.floor(diff / 60).toString().padStart(2, '0');
            const s = (diff % 60).toString().padStart(2, '0');
            countdownEl.textContent = `${m}:${s}`;
            
            if (diff <= 60) {
                countdownEl.classList.remove('text-primary');
                countdownEl.classList.add('text-error');
                countdownEl.previousElementSibling.classList.remove('text-primary');
                countdownEl.previousElementSibling.classList.add('text-error');
                countdownEl.parentElement.classList.add('bg-error-container/10', 'border-error/20');
            } else {
                countdownEl.classList.remove('text-error');
                countdownEl.classList.add('text-primary');
                countdownEl.previousElementSibling.classList.add('text-primary');
                countdownEl.previousElementSibling.classList.remove('text-error');
                countdownEl.parentElement.classList.remove('bg-error-container/10', 'border-error/20');
            }
        };
        updateTimer();
        setInterval(updateTimer, 1000);
        
        // Poll for backend time adjustments (deductions or additions)
        setInterval(() => {
            if (!currentEndTime) return;
            fetch('status_api.php', { cache: 'no-store' })
            .then(res => res.json())
            .then(data => {
                if (data && typeof data.end_time === 'number') {
                    if (data.end_time !== currentEndTime) {
                        const diffSeconds = data.end_time - currentEndTime;
                        const absMins = Math.abs(Math.round(diffSeconds / 60));
                        
                        // Show toast if time drifted cleanly by a minute or more
                        if (Math.abs(diffSeconds) >= 15) { 
                            const isReduced = diffSeconds < 0;
                            const minsLabel = absMins + (absMins === 1 ? ' min' : ' mins');
                            showTimeAdjustmentNotice(minsLabel, isReduced);
                        }
                        
                        currentEndTime = data.end_time;
                        countdownEl.setAttribute('data-endtime', currentEndTime);
                        updateTimer();
                    }
                } else if (data && data.end_time === null) {
                    currentEndTime = 0;
                    location.reload();
                }
            })
            .catch(() => {});
        }, 5000);
    }
}

// Dynamic Announcement Banner Logic (adapted to new UI)
const announcementBanner = document.getElementById('announcementBanner');
const announcementBannerBg = document.getElementById('announcementBannerBg');
const announcementBannerDismiss = document.getElementById('announcementBannerDismiss');
const announcementBannerText = document.getElementById('announcementBannerText');
const announcementBannerTitle = document.getElementById('announcementBannerTitle');
const announcementBannerMeta = document.getElementById('announcementBannerMeta');
const announcementIcon = document.getElementById('announcementIcon');

let announcementInitialized = false;
let lastAnnouncementNotificationAt = 0;
const ANNOUNCEMENT_DISMISS_PREFIX = 'announcementDismissed:';
const ANNOUNCEMENT_DISMISS_TTL_MS = 2 * 60 * 60 * 1000;
const ANNOUNCEMENT_ALERT_COOLDOWN_MS = 30 * 1000;
let lastAnnouncementSignature = '';

function getSeverityMeta(severity) {
    if (severity === 'urgent') {
        return { title: '🚨 URGENT ALERT', toastLabel: 'Urgent', bgClass: 'bg-[#ffe8e8]', textClass: 'text-[#9b1111]', borderClass: 'border-[#f0b9b9]', icon: 'warning' };
    }
    if (severity === 'warning') {
        return { title: '⚠️ WARNING', toastLabel: 'Warning', bgClass: 'bg-[#fff4d8]', textClass: 'text-[#8a5a00]', borderClass: 'border-[#f0d18e]', icon: 'gavel' };
    }
    return { title: 'ℹ️ INFORMATION', toastLabel: 'Information', bgClass: 'bg-[#eef5ff]', textClass: 'text-[#00457b]', borderClass: 'border-[#cad8eb]', icon: 'info' };
}

function applyAnnouncementOffset() {
    const root = document.documentElement;
    if (!announcementBanner || announcementBanner.style.display === 'none') {
        root.style.setProperty('--announcement-offset', '0px');
        return;
    }
    root.style.setProperty('--announcement-offset', `${announcementBanner.offsetHeight || 0}px`);
}

function extractMatricNumber(message) {
    const match = String(message || '').match(/\b\d{6,}\b/);
    return match ? match[0] : null;
}

function formatRelativeTimestamp(updatedAt) {
    if (!updatedAt) return 'Just now';
    const d = new Date(updatedAt);
    if (Number.isNaN(d.getTime())) return 'Just now';
    const diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diff < 45) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)} min ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} hr ago`;
    return `${Math.floor(diff / 86400)} day(s) ago`;
}

function normalizeAnnouncementMessage(message) {
    const text = String(message || '').trim();
    if (!text) return 'An important announcement is currently active.';
    return text.replace(/\b\d{6,}\b/g, '').replace(/\b(issue\s+has\s+been\s+resolved)\b/gi, 'issue resolved successfully').replace(/\s+/g, ' ').replace(/^[-:;,\s]+|[-:;,\s]+$/g, '') || text;
}

function clearDismissFor(signature) {
    try { localStorage.removeItem(ANNOUNCEMENT_DISMISS_PREFIX + signature); } catch (e) {}
}

function isDismissed(signature) {
    try {
        const raw = localStorage.getItem(ANNOUNCEMENT_DISMISS_PREFIX + signature);
        if (!raw) return false;
        const payload = JSON.parse(raw);
        if (!payload || typeof payload.at !== 'number') {
            localStorage.removeItem(ANNOUNCEMENT_DISMISS_PREFIX + signature);
            return false;
        }
        if ((Date.now() - payload.at) > ANNOUNCEMENT_DISMISS_TTL_MS) {
            localStorage.removeItem(ANNOUNCEMENT_DISMISS_PREFIX + signature);
            return false;
        }
        return true;
    } catch (e) {
        return false;
    }
}

function setDismissed(signature) {
    try {
        localStorage.setItem(ANNOUNCEMENT_DISMISS_PREFIX + signature, JSON.stringify({ at: Date.now() }));
    } catch (e) {}
}

function showAnnouncementChangedNotice(severityLabel) {
    const toast = document.createElement('div');
    toast.className = 'announcement-toast';
    toast.textContent = `🔔 ${severityLabel} announcement updated`;
    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 220);
    }, 5200);
}

if (announcementBannerDismiss) {
    announcementBannerDismiss.addEventListener('click', () => {
        if (!lastAnnouncementSignature) return;
        setDismissed(lastAnnouncementSignature);
        if (announcementBanner) {
            announcementBanner.style.display = 'none';
            applyAnnouncementOffset();
        }
    });
}

function fetchAnnouncement() {
    fetch('get_announcement.php', { cache: 'no-store' })
    .then(res => res.json())
    .then(data => {
        const enabled = !!(data && data.enabled);
        const msg = (data && data.message ? String(data.message) : '').trim();
        const severity = (data && data.severity ? String(data.severity) : 'info').toLowerCase();
        const normalizedSeverity = ['info', 'warning', 'urgent'].includes(severity) ? severity : 'info';
        const updatedAt = data && data.updated_at ? String(data.updated_at) : '';
        const signature = JSON.stringify({ enabled, message: msg, severity: normalizedSeverity, updated_at: updatedAt });
        const meta = getSeverityMeta(normalizedSeverity);
        const normalizedMessage = normalizeAnnouncementMessage(msg);
        const matric = extractMatricNumber(msg);
        const relativeUpdatedAt = formatRelativeTimestamp(updatedAt);

        announcementBannerBg.className = `${meta.bgClass} border-b ${meta.borderClass} px-6 py-3 flex items-start sm:items-center justify-between gap-4 w-full`;
        announcementIcon.className = `material-symbols-outlined ${meta.textClass}`;
        announcementIcon.textContent = meta.icon;

        if (announcementBannerTitle) {
            announcementBannerTitle.textContent = meta.title;
        }
        announcementBannerText.textContent = normalizedMessage;
        announcementBannerMeta.textContent = matric ? `Matric No: ${matric} • ${relativeUpdatedAt}` : `System update • ${relativeUpdatedAt}`;

        if (enabled && !isDismissed(signature)) {
            announcementBanner.style.display = 'flex';
        } else {
            announcementBanner.style.display = 'none';
        }
        applyAnnouncementOffset();

        if (announcementInitialized && signature !== lastAnnouncementSignature && lastAnnouncementSignature !== '') {
            clearDismissFor(signature);
            const now = Date.now();
            if ((now - lastAnnouncementNotificationAt) >= ANNOUNCEMENT_ALERT_COOLDOWN_MS) {
                showAnnouncementChangedNotice(meta.toastLabel);
                lastAnnouncementNotificationAt = now;
            }
        }
        lastAnnouncementSignature = signature;
        announcementInitialized = true;
    }).catch(() => {});
}

fetchAnnouncement();
setInterval(fetchAnnouncement, 10000);
window.addEventListener('resize', applyAnnouncementOffset);
</script>
</body>
</html>
