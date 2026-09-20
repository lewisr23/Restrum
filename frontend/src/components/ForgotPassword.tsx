import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { API } from '../lib/config';

/**
 * Ask for a reset link.
 *
 * The success message is deliberately the same whether or not the address
 * has an account, because the backend answers the same way: telling someone
 * an address is unknown turns this page into a way to find out who has an
 * account here.
 */
function ForgotPassword() {
  const [email, setEmail] = useState('');
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSending(true);
    setError('');

    try {
      const res = await fetch(`${API}/api/forgot-password`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email }),
      });

      if (res.ok) {
        setSent(true);
      } else if (res.status === 429) {
        setError('Too many attempts. Wait a few minutes and try again.');
      } else {
        const body = await res.json().catch(() => null);
        setError(body?.message || 'That did not work. Try again in a moment.');
      }
    } catch {
      setError('Could not reach the server.');
    } finally {
      setSending(false);
    }
  };

  if (sent) {
    return (
      <div className="auth-card">
        <h1 className="auth-card__title">Check your email</h1>
        <p className="text-muted">
          If that email has an account, a reset link is on its way. The link is only
          good for a short while, so use it soon.
        </p>
        <p className="auth-card__footer">
          <Link className="auth-card__link" to="/login">Back to log in</Link>
        </p>
      </div>
    );
  }

  return (
    <div className="auth-card">
      <h1 className="auth-card__title">Forgotten your password?</h1>
      <p className="text-muted">
        Put in the email you signed up with and we will send you a link to set a new one.
      </p>

      <form className="auth-card__form" onSubmit={handleSubmit}>
        <div className="field-group">
          <label className="field-label" htmlFor="email">Email</label>
          <input
            className="field"
            id="email"
            type="email"
            value={email}
            onChange={e => setEmail(e.target.value)}
            required
            autoFocus
          />
        </div>

        {error && <p className="field-error">{error}</p>}

        <button className="btn-primary btn-block btn-lg" type="submit" disabled={sending}>
          {sending ? 'Sending...' : 'Send me a link'}
        </button>
      </form>

      <p className="auth-card__footer">
        Remembered it? <Link className="auth-card__link" to="/login">Log in</Link>
      </p>
    </div>
  );
}

export default ForgotPassword;
