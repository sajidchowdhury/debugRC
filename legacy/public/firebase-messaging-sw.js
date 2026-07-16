// public/firebase-messaging-sw.js
// Minimal Service Worker for Firebase Messaging

self.addEventListener('push', function(event) {
  const payload = event.data ? event.data.json() : {};
  
  const title = payload.notification?.title || 'New Sales Invoice';
  const options = {
    body: payload.notification?.body || 'A new sales invoice has been created.',
    icon: '/favicon.ico',
    badge: '/favicon.ico'
  };

  event.waitUntil(
    self.registration.showNotification(title, options)
  );
});

// Fallback for background messages (Firebase v9+)
importScripts('https://www.gstatic.com/firebasejs/12.14.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/12.14.0/firebase-messaging-compat.js');

const firebaseConfig = {
 apiKey: "AIzaSyAIfY7MjKgsXKFzNSqTAN-BCpAY9KBlvDc",
  authDomain: "remote-center.firebaseapp.com",
  projectId: "remote-center",
  storageBucket: "remote-center.firebasestorage.app",
  messagingSenderId: "137628745366",
  appId: "1:137628745366:web:3e7c9b49d80819a00f0cb7",
  measurementId: "G-Q1CJT27KJT"
};

firebase.initializeApp(firebaseConfig);
const messaging = firebase.messaging();

messaging.onBackgroundMessage((payload) => {
  console.log('[SW] Background Message:', payload);
  const title = payload.notification?.title || 'New Sales';
  const options = {
    body: payload.notification?.body || '',
    icon: '/favicon.ico'
  };
  self.registration.showNotification(title, options);
});