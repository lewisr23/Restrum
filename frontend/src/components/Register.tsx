import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

import { API } from '../lib/config';

function Register() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState({ username: '', email: '', password: '', location: '' });
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    try {
      const res = await fetch(`${API}/api/register`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        // password_confirmation mirrors password directly. This form has no
        // separate confirmation field, so this satisfies the API's
        // 'confirmed' validation rule without changing the UX to add one.
        body: JSON.stringify({ ...form, password_confirmation: form.password }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        const firstFieldError = data.errors ? (Object.values(data.errors)[0] as string[] | undefined)?.[0] : undefined;
        setError(firstFieldError || data.message || 'Registration failed.');
        setLoading(false);
        return;
      }
      const data = await res.json();
      login({
        id: data.user.id,
        username: data.user.username,
        email: data.user.email,
        email_verified_at: data.user.email_verified_at ?? null,
        token: data.token,
      });
      navigate('/');
    } catch {
      setError('Could not connect to server.');
    }
    setLoading(false);
  };

  return (
    <div className="auth-card">
      <h2 className="auth-card__title">Create account</h2>
      <form className="auth-card__form" onSubmit={handleSubmit}>
        <input
          className="field"
          type="text"
          placeholder="Username"
          value={form.username}
          onChange={e => setForm({ ...form, username: e.target.value })}
          required
        />
        <input
          className="field"
          type="email"
          placeholder="Email"
          value={form.email}
          onChange={e => setForm({ ...form, email: e.target.value })}
          required
        />
        <input
          className="field"
          type="password"
          placeholder="Password (min 8 characters)"
          value={form.password}
          onChange={e => setForm({ ...form, password: e.target.value })}
          required
        />
        <input
          className="field"
          type="text"
          placeholder="Location (e.g. Newcastle)"
          value={form.location}
          onChange={e => setForm({ ...form, location: e.target.value })}
          required
        />
        {error && <p className="field-error">{error}</p>}
        <button className="btn-primary btn-block btn-lg" type="submit" disabled={loading}>
          {loading ? 'Creating account...' : 'Create account'}
        </button>

        {/* Terms nobody was ever shown are terms that bind nobody, so the
            link sits on the button that accepts them rather than only in the
            footer. Notice rather than a tickbox: a tickbox implies the terms
            are optional, and it is one more thing between a new seller and an
            account. */}
        <p className="auth-card__terms">
          By creating an account you agree to our{' '}
          <Link className="auth-card__link" to="/terms">Terms of Service</Link> and{' '}
          <Link className="auth-card__link" to="/privacy">Privacy Policy</Link>.
        </p>
      </form>
      <p className="auth-card__footer">
        Already have an account? <Link className="auth-card__link" to="/login">Log in</Link>
      </p>
    </div>
  );
}

export default Register;
