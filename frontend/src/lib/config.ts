// Single source of truth for where the backend lives.
//
// Create React App inlines process.env.REACT_APP_* at BUILD time, not at
// runtime — so this is baked into the bundle by `npm run build` and cannot be
// changed by setting an env var on the server afterwards.
//
// Three cases:
//   unset            -> http://localhost:8500  (local dev, Laravel on :8500)
//   set to ""        -> same origin             (frontend served by Laravel itself)
//   set to a URL     -> that host                (split deployment)

const configured = process.env.REACT_APP_API_BASE_URL;

// Note the explicit undefined check rather than a falsy one: "" is a
// meaningful value here (same origin), not an absent one.
const raw = configured === undefined ? 'http://localhost:8500' : configured;

// A trailing slash would produce '//api/...' once callers append their paths.
export const API = raw.replace(/\/+$/, '');

/**
 * Resolves a media reference from the API to something an <img>/<audio>/<video>
 * can load. The backend stores root-relative paths like "/storage/listings/4/x.jpg"
 * (Laravel's public disk), but absolute URLs are passed through untouched so a
 * future move to real object storage needs no frontend change.
 */
export function mediaUrl(url: string): string {
  return url.startsWith('http') ? url : `${API}${url}`;
}

/**
 * Reverb connection details for Laravel Echo (see lib/socket.ts). The app
 * key is not a secret — it identifies which Reverb app to connect to, the
 * same way a Pusher app key works; the actual channel authorization is what
 * keeps private channels private, not this value being hidden.
 */
export const REVERB_CONFIG = {
  key: process.env.REACT_APP_REVERB_APP_KEY || '',
  host: process.env.REACT_APP_REVERB_HOST || 'localhost',
  port: Number(process.env.REACT_APP_REVERB_PORT || 8080),
  scheme: process.env.REACT_APP_REVERB_SCHEME || 'http',
};
