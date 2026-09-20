import React, { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { API } from '../lib/config';

/**
 * Set a new password from an emailed link.
 *
 * Token and email come from the query string, which is where the email
 * built by AppServiceProvider::boot puts them. Neither is editable here:
 * they identify the request, and a form that let you change them would just
 * produce confusing failures.
 */
function ResetPassword() {
  const [params] = useSearchParams();
  const navigate = useNavigate();

  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';

  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (password !== confirmation) {
      setError('Those two passwords are not the same.');
      return;
    }

    setSaving(true);
    setError('');

    try {
      const res = await fetch(`${API}/api/reset-password`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          token,
          email,
          password,
          password_confirmation: confirmation,
        }),
      });

      const body = await res.json().catch(() => null);

      if (res.ok) {
        // Straight to login rather than signing them in here. The reset
        // deliberately destroyed every existing token, and logging in is
        // the proof it worked.
        navigate('/login?reset=1');
        return;
      }

      setError(
        body?.errors?.password?.[0]
          || body?.message
          || 'That did not work. Ask for a new link.'
      );
    } catch {
      setError('Could not reach the server.');
    } finally {
      setSaving(false);
    }
  };

  if (!token || !email) {
    return (
      <div className="auth-card">
        <h1 className="auth-card__title">That link is incomplete</h1>
        <p className="text-muted">
          Open the link from the email exactly as it was sent, or ask for a new one.
        </p>
        <p className="auth-card__footer">
          <Link className="auth-card__link" to="/forgot-password">Send me a new link</Link>
        </p>
      </div>
    );
  }

  return (
    <div className="auth-card">
      <h1 className="auth-card__title">Choose a new password</h1>
      <p className="text-muted">Setting this signs you out everywhere else.</p>

      <form className="auth-card__form" onSubmit={handleSubmit}>
        <div className="field-group">
          <label className="field-label" htmlFor="password">New password</label>
          <input
            className="field"
            id="password"
            type="password"
            value={password}
            onChange={e => setPassword(e.target.value)}
            required
            autoFocus
          />
        </div>

        <div className="field-group">
          <label className="field-label" htmlFor="confirmation">Again, to be sure</label>
          <input
            className="field"
            id="confirmation"
            type="password"
            value={confirmation}
            onChange={e => setConfirmation(e.target.value)}
            required
          />
        </div>

        {error && <p className="field-error">{error}</p>}

        <button className="btn-primary btn-block btn-lg" type="submit" disabled={saving}>
          {saving ? 'Saving...' : 'Set new password'}
        </button>
      </form>
    </div>
  );
}

export default ResetPassword;
