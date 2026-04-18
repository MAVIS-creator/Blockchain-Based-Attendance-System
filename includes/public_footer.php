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
            <a class="font-inter text-xs font-normal leading-relaxed text-[#424750] dark:text-[#c2c7d1] opacity-80 hover:opacity-100 hover:underline decoration-[#00457b] underline-offset-4 transition-all" href="#">Privacy Policy</a>
            <a class="font-inter text-xs font-normal leading-relaxed text-[#424750] dark:text-[#c2c7d1] opacity-80 hover:opacity-100 hover:underline decoration-[#00457b] underline-offset-4 transition-all" href="#">Terms of Service</a>
            <a class="font-inter text-xs font-normal leading-relaxed text-[#424750] dark:text-[#c2c7d1] opacity-80 hover:opacity-100 hover:underline decoration-[#00457b] underline-offset-4 transition-all" href="support.php">Technical Support</a>
        </div>
    </div>
</footer>

<script>
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
const ANNOUNCEMENT_ALERT_COOLDOWN_MS = 30 * 1000;
let lastAnnouncementSignature = '';

function getSeverityMeta(severity) {
    if (severity === 'urgent') {
        return { title: '🚨 URGENT ALERT', toastLabel: 'Urgent', bgClass: 'bg-error-container/30', textClass: 'text-error', borderClass: 'border-error/20', icon: 'warning' };
    }
    if (severity === 'warning') {
        return { title: '⚠️ WARNING', toastLabel: 'Warning', bgClass: 'bg-[#fff8e8]', textClass: 'text-[#8a5a00]', borderClass: 'border-[#f5dfad]', icon: 'gavel' };
    }
    return { title: 'ℹ️ INFORMATION', toastLabel: 'Information', bgClass: 'bg-tertiary-container/10', textClass: 'text-primary', borderClass: 'border-outline-variant/20', icon: 'info' };
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
    try { return localStorage.getItem(ANNOUNCEMENT_DISMISS_PREFIX + signature) === '1'; } catch (e) { return false; }
}

function setDismissed(signature) {
    try { localStorage.setItem(ANNOUNCEMENT_DISMISS_PREFIX + signature, '1'); } catch (e) {}
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
</script>
</body>
</html>
