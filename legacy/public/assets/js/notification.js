  // === NOTIFICATION FUNCTIONS ===
  const BASE_URL = '/remote-center-erp/';

        let unreadCount = 0;
        const notificationSound = document.getElementById('notificationSound');

        function playNotificationSound() {
            notificationSound.currentTime = 0;
            notificationSound.play().catch(() => {});
        }

        function showBeautifulNotification(title, message, invoiceId = null) {
            const container = document.getElementById('notificationContainer');
            const toast = document.createElement('div');
            toast.className = 'custom-toast';
            toast.innerHTML = `
                <div class="toast-header d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-shopping-cart text-info"></i>
                        <span>${title}</span>
                    </div>
                    <button class="btn-close btn-close-white" onclick="this.closest('.custom-toast').remove()"></button>
                </div>
                <div class="toast-body">
                    ${message}
                    ${invoiceId ? `<hr class="my-2"><a href="sales/today" class="btn btn-sm btn-outline-light w-100">View Invoice →</a>` : ''}
                </div>
            `;
            container.appendChild(toast);
            playNotificationSound();
            updateNotificationBadge(unreadCount + 1);

            setTimeout(() => toast.remove(), 8000);
        }

        function updateNotificationBadge(count) {
            unreadCount = count;
            const badge = document.getElementById('notifBadge');
            if (badge) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = count > 0 ? 'inline-block' : 'none';
            }
        }

        function lightCheckNotifications() {
            fetch('<?= BASE_URL ?>notifications/unread')
                .then(r => r.ok ? r.json() : {})
                .then(data => {
                    if (data.status === 'success') updateNotificationBadge(data.notifications.length);
                })
                .catch(() => {});
        }

             import { initializeApp } from "https://www.gstatic.com/firebasejs/12.14.0/firebase-app.js";
        import { getMessaging, getToken, onMessage } from "https://www.gstatic.com/firebasejs/12.14.0/firebase-messaging.js";

        const firebaseConfig = {
    apiKey: "AIzaSyAIfY7MjKgsXKFzNSqTAN-BCpAY9KBlvDc",
    authDomain: "remote-center.firebaseapp.com",
    projectId: "remote-center",
    storageBucket: "remote-center.firebasestorage.app",
    messagingSenderId: "137628745366",
    appId: "1:137628745366:web:3e7c9b49d80819a00f0cb7",
    measurementId: "G-Q1CJT27KJT"
};

        const app = initializeApp(firebaseConfig);
        const messaging = getMessaging(app);

        async function requestFCMToken() {
            const vapidKey = (window.FCM_VAPID_KEY || '').trim();
            if (!vapidKey) {
                return;
            }
            try {
                const permission = await Notification.requestPermission();
                if (permission === "granted") {
                    const swUrl = BASE_URL + 'firebase-messaging-sw.js';
                    const registration = await navigator.serviceWorker.register(swUrl);
                    const token = await getToken(messaging, { 
                        vapidKey,
                        serviceWorkerRegistration: registration 
                    });

                    if (token) {
                        const csrf = (window.CSRF_TOKEN || '').trim();
                        await fetch(BASE_URL + 'save-fcm-token', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify({
                                token: token,
                                device_info: navigator.userAgent,
                                csrf_token: csrf,
                            }),
                        });
                    }
                }
            } catch(e) { console.error(e); }
        }

        onMessage(messaging, (payload) => {
            const title = payload.notification?.title || "New Sales";
            const body = payload.notification?.body || "";
            const invoiceId = payload.data?.invoice_id || null;
            showBeautifulNotification(title, body, invoiceId);
        });

        window.addEventListener('load', requestFCMToken);

        
// Expose globally for Firebase
window.showBeautifulNotification = showBeautifulNotification;