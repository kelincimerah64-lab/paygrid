(() => {
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    /**
     * Minimal vanilla-JS client for Reverb's Pusher-compatible protocol -
     * written by hand instead of vendoring pusher-js/Echo since this app only
     * needs one thing: subscribe to a private channel and react to one event
     * type. Handles private-channel auth (via /broadcasting/auth, reusing the
     * session's own CSRF token) and reconnects with backoff on drop,
     * re-subscribing everything once the new connection is established.
     */
    function connect({ key, host, port, scheme }) {
        const wsScheme = scheme === 'https' ? 'wss' : 'ws';
        const url = `${wsScheme}://${host}:${port}/app/${encodeURIComponent(key)}?protocol=7&client=paygrid&version=1.0`;

        const state = {
            ws: null,
            socketId: null,
            subscriptions: new Map(), // channelName -> { eventName, callback }
            reconnectDelay: 1000,
        };

        const subscribeNow = async (channelName, sub) => {
            if (!state.socketId || !state.ws || state.ws.readyState !== WebSocket.OPEN) return;
            try {
                const response = await fetch('/broadcasting/auth', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new URLSearchParams({ channel_name: channelName, socket_id: state.socketId }),
                });
                if (!response.ok) return;
                const { auth } = await response.json();
                state.ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: channelName, auth } }));
            } catch (e) {
                // network hiccup - the next reconnect cycle re-subscribes everything anyway
            }
        };

        const handleMessage = (raw) => {
            let msg;
            try {
                msg = JSON.parse(raw);
            } catch (e) {
                return;
            }
            if (msg.event === 'pusher:connection_established') {
                state.socketId = JSON.parse(msg.data).socket_id;
                state.subscriptions.forEach((sub, channelName) => subscribeNow(channelName, sub));
                return;
            }
            if (msg.event === 'pusher:error' || msg.event === 'pusher:subscription_error') {
                return;
            }
            const sub = state.subscriptions.get(msg.channel);
            if (!sub || msg.event !== sub.eventName) return;
            let data = msg.data;
            try {
                data = JSON.parse(msg.data);
            } catch (e) {
                // leave as raw string
            }
            sub.callback(data);
        };

        const open = () => {
            state.ws = new WebSocket(url);
            state.ws.addEventListener('open', () => {
                state.reconnectDelay = 1000;
            });
            state.ws.addEventListener('message', (event) => handleMessage(event.data));
            state.ws.addEventListener('close', () => {
                state.socketId = null;
                window.setTimeout(open, state.reconnectDelay);
                state.reconnectDelay = Math.min(state.reconnectDelay * 2, 30000);
            });
            state.ws.addEventListener('error', () => {
                try { state.ws.close(); } catch (e) { /* already closing */ }
            });
        };

        open();

        return {
            subscribePrivate(channelName, eventName, callback) {
                state.subscriptions.set(channelName, { eventName, callback });
                subscribeNow(channelName, state.subscriptions.get(channelName));
            },
        };
    }

    window.PayGridRealtime = { connect };
})();
