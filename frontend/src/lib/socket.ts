import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

import { API, REVERB_CONFIG } from './config';

// laravel-echo expects a global Pusher when using the 'reverb' broadcaster
// (Reverb speaks the Pusher protocol) - this is how the two packages are
// wired together, not an accidental dependency.
(window as any).Pusher = Pusher;

/**
 * One Echo client per session. Auth happens over a plain HTTP POST to
 * /broadcasting/auth with the Bearer token (see routes/channels.php and
 * bootstrap/app.php's withBroadcasting() on the backend) - not a token
 * carried in the WebSocket handshake itself.
 * Each private channel subscription re-runs that HTTP auth check, so a
 * user who no longer has access to a conversation is rejected per-channel,
 * not just at initial connection.
 */
export function createEcho(token: string): Echo<'reverb'> {
  return new Echo({
    broadcaster: 'reverb',
    key: REVERB_CONFIG.key,
    wsHost: REVERB_CONFIG.host,
    wsPort: REVERB_CONFIG.port,
    forceTLS: REVERB_CONFIG.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${API}/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    },
  });
}
