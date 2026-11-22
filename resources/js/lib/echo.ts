import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echoInstance: Echo<'pusher'> | null = null;

function readXsrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    if (!match?.[1]) {
        return null;
    }

    try {
        return decodeURIComponent(match[1]);
    } catch {
        return match[1];
    }
}

export function getEcho(): Echo<'pusher'> | null {
    if (echoInstance !== null) {
        return echoInstance;
    }

    const rawKey = import.meta.env.VITE_PUSHER_APP_KEY;
    const appKey = typeof rawKey === 'string' ? rawKey.trim() : '';

    if (appKey === '') {
        return null;
    }

    const rawCluster = import.meta.env.VITE_PUSHER_APP_CLUSTER;
    const cluster = typeof rawCluster === 'string' && rawCluster.trim() !== '' ? rawCluster.trim() : 'mt1';

    echoInstance = new Echo({
        broadcaster: 'pusher',
        Pusher,
        key: appKey,
        cluster,
        forceTLS: true,
        authEndpoint: '/broadcasting/auth',
        authorizer: (channel) => ({
            authorize: (socketId, callback) => {
                const xsrfToken = readXsrfToken();

                fetch('/broadcasting/auth', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(xsrfToken ? { 'X-XSRF-TOKEN': xsrfToken } : {}),
                    },
                    body: JSON.stringify({
                        socket_id: socketId,
                        channel_name: channel.name,
                    }),
                })
                    .then(async (response) => {
                        if (!response.ok) {
                            throw new Error(`Broadcast auth failed (${response.status})`);
                        }

                        return (await response.json()) as {
                            auth: string;
                            channel_data?: string;
                            shared_secret?: string;
                        };
                    })
                    .then((data) => {
                        callback(null, data);
                    })
                    .catch((error: unknown) => {
                        callback(error instanceof Error ? error : new Error('Broadcast auth failed'), null);
                    });
            },
        }),
    });

    return echoInstance;
}

export function disconnectEcho(): void {
    if (echoInstance !== null) {
        echoInstance.disconnect();
        echoInstance = null;
    }
}
