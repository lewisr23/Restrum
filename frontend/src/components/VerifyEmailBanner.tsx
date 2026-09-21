import { useEffect, useState } from 'react';
import { useAuth } from '../context/AuthContext';
import { API } from '../lib/config';

/**
 * The nag that gets the link clicked.
 *
 * Shows for signed-in members whose address is not confirmed, and is the
 * only route back for anyone whose link expired, so it stays visible rather
 * than being dismissable. It renders nothing at all for verified members,
 * which is everybody who has done the thirty seconds of work it asks for.
 */
function VerifyEmailBanner() {
  const { user, updateUser } = useAuth();
  const [sending, setSending] = useState(false);
  const [message, setMessage] = useState('');

  const unknown = user !== null && user.email_verified_at === undefined;

  // A record stored before this feature existed has no verified field at
  // all. Asking the server once is better than assuming: assuming verified
  // hides the banner from people who need it, and assuming unverified nags
  // people who do not.
  useEffect(() => {
    if (!unknown) return;

    let cancelled = false;

    fetch(`${API}/api/me`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${user!.token}` },
    })
      .then(res => (res.ok ? res.json() : null))
      .then(body => {
        if (!cancelled && body) {
          updateUser({ email_verified_at: body.email_verified_at ?? null });
        }
      })
      .catch(() => {
        // Offline or the API is down. Staying quiet is right: a banner
        // about an unconfirmed address is not the useful thing to say when
        // nothing can reach the server anyway.
      });

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [unknown]);

  const resend = async () => {
    setSending(true);
    setMessage('');

    try {
      const res = await fetch(`${API}/api/email/verification-notification`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${user!.token}` },
      });

      if (res.status === 429) {
        setMessage('One at a time. Wait a minute before asking again.');
      } else if (res.ok) {
        const body = await res.json().catch(() => null);
        setMessage(body?.message || 'Sent. Check your inbox, and the spam folder.');
      } else {
        setMessage('Could not send it just now. Try again shortly.');
      }
    } catch {
      setMessage('Could not reach the server.');
    } finally {
      setSending(false);
    }
  };

  if (!user || user.email_verified_at !== null) return null;

  return (
    <div className="verify-banner" role="status">
      <p className="verify-banner__text">
        <strong>Confirm your email address.</strong> We sent a link to {user.email}.
        Until it is confirmed we have no way to reach you if a sale goes wrong.
      </p>

      <div className="verify-banner__actions">
        <button className="verify-banner__button" onClick={resend} disabled={sending}>
          {sending ? 'Sending...' : 'Send it again'}
        </button>
        {message && <span className="verify-banner__message">{message}</span>}
      </div>
    </div>
  );
}

export default VerifyEmailBanner;
