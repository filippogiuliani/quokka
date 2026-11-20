import { addObservablesTo } from 'External/ko';

/**
 * Might not work due to the new ServiceWorkerRegistration.showNotification
 */
const HTML5Notification = window.Notification,
	HTML5NotificationStatus = () => HTML5Notification?.permission || 'denied',
	NotificationsDenied = () => 'denied' === HTML5NotificationStatus(),
	NotificationsGranted = () => 'granted' === HTML5NotificationStatus(),
	dispatchMessage = data => {
		focus();
		hasher.setHash(data.Url);
	};

let DesktopNotifications = false,
	WorkerNotifications = navigator.serviceWorker;

// Are Notifications supported in the service worker?
if (WorkerNotifications) {
	if (ServiceWorkerRegistration && ServiceWorkerRegistration.prototype.showNotification) {
		/* Listen for close requests from the ServiceWorker */
		WorkerNotifications.addEventListener('message', event => {
			const obj = JSON.parse(event.data);
			'notificationclick' === obj?.action && dispatchMessage(obj.data);
		});
	} else {
		console.log('ServiceWorkerRegistration.showNotification undefined');
		WorkerNotifications = null;
	}
} else {
	console.log('ServiceWorker undefined');
}

export const NotificationUserStore = new class {
	constructor() {
		addObservablesTo(this, {
			enabled: false,/*.extend({ notify: 'always' })*/
			allowed: !NotificationsDenied()
		});

		this.enabled.subscribe(value => {
			DesktopNotifications = !!value;
			if (value && HTML5Notification && !NotificationsGranted()) {
				HTML5Notification.requestPermission(() =>
					this.allowed(!NotificationsDenied())
				);
			}
		});
	}

	/**
	 * Used with DesktopNotifications setting
	 */
	display(title, text, messageData, imageSrc) {
		// Quokka: this device already gets this mailbox's notifications from the sorter (push): not a second one
		try {
			if (localStorage.getItem('quokka.push.' + String(rl.settings.get('Email') || '').toLowerCase())) return;
		} catch (e) { /* storage off */ }
		if (DesktopNotifications && NotificationsGranted()) {
			// Quokka: the quokka (not SnappyMail's envelope); the face in white in the bar
			const options = {
				body: text,
				icon: imageSrc || './quokka-icon.png',
				badge: './quokka-badge.png',
				data: messageData
			};
			if (messageData?.uid) {
				options.tag = messageData.uid;
			}
			if (WorkerNotifications) {
				// Quokka: the one service worker, at the root of the site (also the push notifications)
				WorkerNotifications.register('./quokka-sw.js', {scope:'./'})
				.then(() =>
					WorkerNotifications.ready.then(registration =>
						/* Show the notification */
						registration
							.showNotification(title, options)
							.then(() =>
								registration.getNotifications().then((/*notifications*/) => {
									/* Send an empty message so the Worker knows who the client is */
									registration.active.postMessage('');
								})
							)
					)
				)
				.catch(e => {
					console.error(e);
					WorkerNotifications = null;
				});
			} else {
				const notification = new HTML5Notification(title, options);
				notification.show?.();
				notification.onclick = messageData ? () => dispatchMessage(messageData) : null;
				setTimeout(() => notification.close(), 7000);
			}
		}
	}
};
