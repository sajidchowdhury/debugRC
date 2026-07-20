  // === NOTIFICATION FUNCTIONS ===
  // Laravel Push Notification system — database + broadcast channels.
  // Firebase/FCM removed in favor of Laravel's native notification system.

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
      fetch(BASE_URL + 'notifications/unread')
          .then(r => r.ok ? r.json() : {})
          .then(data => {
              if (data.status === 'success') updateNotificationBadge(data.notifications.length);
          })
          .catch(() => {});
  }

  // Expose globally
  window.showBeautifulNotification = showBeautifulNotification;
