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
    <div style={{ maxWidth: '400px', margin: '80px auto', padding: '32px', background: '#111', borderRadius: '8px', color: 'white' }}>
      <h2 style={{ marginTop: 0 }}>Log in</h2>
      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
        <input
          type="text"
          placeholder="Email or username"
          value={form.login}
          onChange={e => setForm({ ...form, login: e.target.value })}
          required
          style={{ padding: '10px', background: '#1a1a1a', border: '1px solid #444', color: 'white', borderRadius: '4px' }}
        />
        <input
          type="password"
          placeholder="Password"
          value={form.password}
          onChange={e => setForm({ ...form, password: e.target.value })}
          required
          style={{ padding: '10px', background: '#1a1a1a', border: '1px solid #444', color: 'white', borderRadius: '4px' }}
        />
        {error && <p style={{ color: '#f44', margin: 0 }}>{error}</p>}
        <button
          type="submit"
          disabled={loading}
          style={{ padding: '12px', background: '#4caf50', border: 'none', color: 'white', borderRadius: '4px', cursor: 'pointer', fontSize: '16px' }}
        >
          {loading ? 'Logging in...' : 'Log in'}
        </button>
      </form>
      <p style={{ color: '#888', marginTop: '24px', textAlign: 'center' }}>
        Don't have an account? <Link to="/register" style={{ color: '#4caf50' }}>Register</Link>
      </p>
    </div>
  );
}

export default Login;
