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
        // password_confirmation matches password directly - there's no
        // separate confirm-password field in this form, so this just
        // satisfies the API's 'confirmed' validation rule silently rather
        // than changing the UX to add one.
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
      login({ id: data.user.id, username: data.user.username, email: data.user.email, token: data.token });
      navigate('/');
    } catch {
      setError('Could not connect to server.');
    }
    setLoading(false);
  };

  return (
    <div style={{ maxWidth: '400px', margin: '80px auto', padding: '32px', background: '#111', borderRadius: '8px', color: 'white' }}>
      <h2 style={{ marginTop: 0 }}>Create account</h2>
      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
        <input
          type="text"
          placeholder="Username"
          value={form.username}
          onChange={e => setForm({ ...form, username: e.target.value })}
          required
          style={{ padding: '10px', background: '#1a1a1a', border: '1px solid #444', color: 'white', borderRadius: '4px' }}
        />
        <input
          type="email"
          placeholder="Email"
          value={form.email}
          onChange={e => setForm({ ...form, email: e.target.value })}
          required
          style={{ padding: '10px', background: '#1a1a1a', border: '1px solid #444', color: 'white', borderRadius: '4px' }}
        />
        <input
          type="password"
          placeholder="Password (min 8 characters)"
          value={form.password}
          onChange={e => setForm({ ...form, password: e.target.value })}
          required
          style={{ padding: '10px', background: '#1a1a1a', border: '1px solid #444', color: 'white', borderRadius: '4px' }}
        />
        <input
          type="text"
          placeholder="Location (e.g. Newcastle)"
          value={form.location}
          onChange={e => setForm({ ...form, location: e.target.value })}
          required
          style={{ padding: '10px', background: '#1a1a1a', border: '1px solid #444', color: 'white', borderRadius: '4px' }}
        />
        {error && <p style={{ color: '#f44', margin: 0 }}>{error}</p>}
        <button
          type="submit"
          disabled={loading}
          style={{ padding: '12px', background: '#4caf50', border: 'none', color: 'white', borderRadius: '4px', cursor: 'pointer', fontSize: '16px' }}
        >
          {loading ? 'Creating account...' : 'Create account'}
        </button>
      </form>
      <p style={{ color: '#888', marginTop: '24px', textAlign: 'center' }}>
        Already have an account? <Link to="/login" style={{ color: '#4caf50' }}>Log in</Link>
      </p>
    </div>
  );
}

export default Register;
