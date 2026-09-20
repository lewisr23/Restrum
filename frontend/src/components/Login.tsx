import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

import { API } from '../lib/config';

function Login() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState({ login: '', password: '' });
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    try {
      const res = await fetch(`${API}/api/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ login: form.login, password: form.password }),
      });
      if (!res.ok) {
        setError('Invalid email or password.');
        setLoading(false);
        return;
      }
      const data = await res.json();
      login({ id: data.user.id, username: data.user.username, email: data.user.email, token: data.token });
      navigate('/');
    } catch {
      setError('Could not connect to server.');
    }
    setLoading(false);
  };

  return (
    <div className="auth-card">
      <h2 className="auth-card__title">Log in</h2>
      <form className="auth-card__form" onSubmit={handleSubmit}>
        <input
          className="field"
          type="text"
          placeholder="Email or username"
          value={form.login}
          onChange={e => setForm({ ...form, login: e.target.value })}
          required
        />
        <input
          className="field"
          type="password"
          placeholder="Password"
          value={form.password}
          onChange={e => setForm({ ...form, password: e.target.value })}
          required
        />
        {error && <p className="field-error">{error}</p>}
        <button className="btn-primary btn-block btn-lg" type="submit" disabled={loading}>
          {loading ? 'Logging in...' : 'Log in'}
        </button>
      </form>
      <p className="auth-card__footer">
        <Link className="auth-card__link" to="/forgot-password">Forgotten your password?</Link>
      </p>
      <p className="auth-card__footer">
        Don't have an account? <Link className="auth-card__link" to="/register">Register</Link>
      </p>
    </div>
  );
}

export default Login;
