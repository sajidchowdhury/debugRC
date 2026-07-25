  // === NOTIFICATION FUNCTIONS ===
  // Laravel native notification system — database channel.
  // Phase 1E (Task 31): Upgraded from AJAX polling to SSE (Server-Sent Events)
  // with automatic fallback to polling if SSE is unavailable.
  // Phase 4 F-18a: Fixed BASE_URL (was '/remote-center-erp/' — routes are
  // root-relative) + fixed the unread-count endpoint (was the non-existent
  // 'notifications/unread'; now 'admin/notifications/unread-count' which
  // returns {count: N}) + null guards on notificationSound. This file is
  // now loaded by layouts/admin.blade.php on every authenticated page.
  // NOTE (2026-07-22): Telegram + FCM push notifications are intentionally
  // NOT implemented — see docs/sales_entry_Lg_vs_La.md R24/R25 (removed).
  // NOTE (Phase 4 F-18a): Real-time delivery is via SSE
  // (PostgreSQL LISTEN/NOTIFY → Redis → EventSource), NOT Laravel
  // broadcasting — no continuous database polling.

  const BASE_URL = '/';

  let unreadCount = 0;
  let eventSource = null;
  let pollInterval = null;
  let sseRetries = 0;
  const MAX_SSE_RETRIES = 5;
  const notificationSound = document.getElementById('notificationSound');

  // ============================================================
  // SSE (Server-Sent Events) — Real-Time Notification Stream
  // ============================================================
  // Connects to /sse/events and receives pushed events from
  // PostgreSQL LISTEN/NOTIFY via Redis Pub/Sub.
  //
  // Event types received from the SSE endpoint:
  //   rcerp_sales_invoice   — Invoice finalized, status changed
  //   rcerp_sales_challan   — Challan issued, reversed
  //   rcerp_sales_return    — Return created, confirmed, reversed
  //   rcerp_customer_payment — Payment received, cancelled
  //   rcerp_stock_change    — Stock level changed
  //   rcerp_journal_entry   — GL entry posted
  //   rcerp_system          — System policy changed
  //   rcerp_notification_dispatched — Notification sent by NotificationService
  //   connected             — SSE connection established
  //   heartbeat             — Keep-alive ping
  //   error                 — SSE/stream error
  //   reconnect             — Server instructs reconnect

  function initSSE() {
      // Don't initialize if already connected
      if (eventSource && eventSource.readyState !== EventSource.CLOSED) {
          return;
      }

      try {
          eventSource = new EventSource(BASE_URL + 'sse/events');

          eventSource.addEventListener('connected', function(e) {
              console.log('[SSE] Connected:', JSON.parse(e.data));
              sseRetries = 0; // Reset retry counter on successful connection
              // Stop polling if SSE is working
              stopPolling();
          });

          // --- Business Event Handlers ---

          eventSource.addEventListener('rcerp_notification_dispatched', function(e) {
              const data = JSON.parse(e.data);
              const changes = data.changes || {};
              showBeautifulNotification(
                  changes.title || 'New Notification',
                  changes.body || 'You have a new notification',
                  changes.reference_type === 'sales_invoice' ? changes.reference_id : null
              );
              // Refresh unread count from server (source of truth)
              lightCheckNotifications();
          });

          eventSource.addEventListener('rcerp_sales_invoice', function(e) {
              const data = JSON.parse(e.data);
              const changes = data.changes || {};
              // Status change notifications
              if (changes.status === 'finalized' || changes.status === 'confirmed') {
                  showBeautifulNotification(
                      'Invoice Updated',
                      `Invoice #${data.id} status changed to ${changes.status}`,
                      data.id
                  );
              } else if (changes.is_challan_issued === true) {
                  showBeautifulNotification(
                      'Challan Issued',
                      `Challan issued for Invoice #${data.id}`,
                      data.id
                  );
              } else if (changes.is_reversed === true) {
                  showBeautifulNotification(
                      'Invoice Reversed',
                      `Invoice #${data.id} has been reversed`,
                      data.id
                  );
              }
              // Trigger dashboard refresh if available
              if (typeof refreshDashboard === 'function') refreshDashboard();
          });

          eventSource.addEventListener('rcerp_customer_payment', function(e) {
              const data = JSON.parse(e.data);
              const changes = data.changes || {};
              if (changes.status === 'confirmed' || data.action === 'INSERT') {
                  showBeautifulNotification(
                      'Payment Received',
                      `Payment #${data.id} recorded`,
                      null
                  );
              }
              // Refresh payment-related UI
              if (typeof refreshPayments === 'function') refreshPayments();
          });

          eventSource.addEventListener('rcerp_sales_return', function(e) {
              const data = JSON.parse(e.data);
              const changes = data.changes || {};
              if (changes.status) {
                  showBeautifulNotification(
                      'Return Updated',
                      `Return #${data.id} status: ${changes.status}`,
                      data.id
                  );
              }
          });

          eventSource.addEventListener('rcerp_stock_change', function(e) {
              // Stock changes are high-frequency — update UI without toast
              if (typeof refreshStockDisplay === 'function') refreshStockDisplay();
          });

          eventSource.addEventListener('rcerp_journal_entry', function(e) {
              // Journal entries update dashboards silently
              if (typeof refreshGLDashboard === 'function') refreshGLDashboard();
          });

          eventSource.addEventListener('rcerp_system', function(e) {
              const data = JSON.parse(e.data);
              const changes = data.changes || {};
              showBeautifulNotification(
                  'System Policy Changed',
                  `Policy "${changes.policy_key || ''}" changed from ${changes.old_mode || ''} to ${changes.new_mode || ''}`,
                  null
              );
          });

          eventSource.addEventListener('heartbeat', function(e) {
              // Heartbeat received — connection is alive
              console.log('[SSE] Heartbeat');
          });

          eventSource.addEventListener('reconnect', function(e) {
              const data = JSON.parse(e.data);
              console.log('[SSE] Server requests reconnect:', data.reason);
              const retryMs = data.retry_after_ms || 3000;
              closeSSE();
              setTimeout(initSSE, retryMs);
          });

          eventSource.addEventListener('error', function(e) {
              console.warn('[SSE] Connection error. ReadyState:', eventSource.readyState);
              if (eventSource.readyState === EventSource.CLOSED) {
                  sseRetries++;
                  if (sseRetries <= MAX_SSE_RETRIES) {
                      const backoff = Math.min(1000 * Math.pow(2, sseRetries), 30000);
                      console.log(`[SSE] Retrying in ${backoff}ms (attempt ${sseRetries}/${MAX_SSE_RETRIES})`);
                      setTimeout(initSSE, backoff);
                  } else {
                      console.warn('[SSE] Max retries reached. Falling back to polling.');
                      startPolling();
                  }
              }
              // If readyState is CONNECTING, EventSource auto-reconnects
          });

      } catch (e) {
          console.warn('[SSE] Failed to initialize. Falling back to polling.', e);
          startPolling();
      }
  }

  function closeSSE() {
      if (eventSource) {
          eventSource.close();
          eventSource = null;
      }
  }

  // ============================================================
  // AJAX Polling — Fallback when SSE is unavailable
  // ============================================================

  function startPolling() {
      if (pollInterval) return; // Already polling
      console.log('[Notifications] Starting AJAX polling fallback (30s interval)');
      // Check immediately
      lightCheckNotifications();
      // Then every 30 seconds
      pollInterval = setInterval(lightCheckNotifications, 30000);
  }

  function stopPolling() {
      if (pollInterval) {
          clearInterval(pollInterval);
          pollInterval = null;
          console.log('[Notifications] Stopped AJAX polling (SSE is active)');
      }
  }

  // ============================================================
  // Shared Functions (used by both SSE and polling)
  // ============================================================

  function playNotificationSound() {
      if (!notificationSound) return; // Phase 4 F-18a: null guard (audio element may be absent)
      try {
          notificationSound.currentTime = 0;
          notificationSound.play().catch(() => {});
      } catch (e) { /* no audio element — silent */ }
  }

  function showBeautifulNotification(title, message, invoiceId = null) {
      const container = document.getElementById('notificationContainer');
      if (!container) return; // No container on this page

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
      // Phase 4 F-18a: fixed URL (was 'notifications/unread' — 404) + response
      // shape (was data.notifications.length; admin/notifications/unread-count
      // returns {count: N}).
      fetch(BASE_URL + 'admin/notifications/unread-count')
          .then(r => r.ok ? r.json() : {})
          .then(data => {
              if (typeof data.count !== 'undefined') updateNotificationBadge(data.count);
          })
          .catch(() => {});
  }

  // ============================================================
  // Check SSE status and initialize
  // ============================================================

  function checkSSEAndInit() {
      fetch(BASE_URL + 'sse/status')
          .then(r => r.ok ? r.json() : {})
          .then(data => {
              if (data.status === 'active' && data.pg_available) {
                  console.log('[SSE] Server supports LISTEN/NOTIFY. Initializing SSE.');
                  initSSE();
              } else {
                  console.log('[SSE] Server does not support LISTEN/NOTIFY. Using polling.');
                  startPolling();
              }
          })
          .catch(() => {
              console.log('[SSE] Status check failed. Using polling.');
              startPolling();
          });
  }

  // Auto-initialize on page load
  // Check SSE availability first, then choose SSE or polling
  if (typeof EventSource !== 'undefined') {
      // Browser supports SSE — check if server supports it too
      checkSSEAndInit();
  } else {
      // Browser doesn't support SSE — use polling
      console.log('[Notifications] Browser does not support SSE. Using polling.');
      startPolling();
  }

  // Expose globally (Phase 4 F-18a: the layout's inline dropdown JS calls
  // updateNotificationBadge + lightCheckNotifications via window.*).
  window.showBeautifulNotification = showBeautifulNotification;
  window.updateNotificationBadge = updateNotificationBadge;
  window.lightCheckNotifications = lightCheckNotifications;
  window.initSSE = initSSE;
  window.startPolling = startPolling;
