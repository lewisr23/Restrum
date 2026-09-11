import { useState, useRef, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

// Account dropdown — consolidates "My Listings" (new — the only way to reach
// your own listings before this was hunting the homepage grid, same as a
// stranger would), Saved, and Messages under one menu instead of loose
// navbar buttons, so the navbar doesn't just keep growing sideways as more
// account-level pages get added.
function AccountMenu({ username, userId }: { username: string; userId: number }) {
  const navigate = useNavigate();
  const { logout } = useAuth();
  const [open, setOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const go = (path: string) => {
    setOpen(false);
    navigate(path);
  };

  const handleLogout = () => {
    setOpen(false);
    logout();
    navigate('/');
  };

  const itemStyle: React.CSSProperties = {
    display: 'block',
    width: '100%',
    textAlign: 'left',
    padding: '10px 16px',
    background: 'none',
    border: 'none',
    color: '#ddd',
    cursor: 'pointer',
    fontSize: '14px',
  };

  return (
    <div ref={menuRef} style={{ position: 'relative' }}>
      <button
        onClick={() => setOpen(!open)}
        style={{ padding: '10px 16px', background: 'none', color: 'white', border: '1px solid #444', borderRadius: '4px', cursor: 'pointer', fontSize: '14px', display: 'flex', alignItems: 'center', gap: '6px' }}
      >
        Hi, {username} <span style={{ fontSize: '10px', color: '#888' }}>{open ? '▲' : '▼'}</span>
      </button>

      {open && (
        <div
          style={{
            position: 'absolute',
            top: 'calc(100% + 6px)',
            right: 0,
            background: '#1a1a1a',
            border: '1px solid #444',
            borderRadius: '6px',
            minWidth: '180px',
            overflow: 'hidden',
            zIndex: 10,
            boxShadow: '0 4px 12px rgba(0,0,0,0.4)',
          }}
        >
          <button style={itemStyle} onClick={() => go(`/seller/${userId}`)}>My Listings</button>
          <button style={itemStyle} onClick={() => go('/saved')}>Saved</button>
          <button style={itemStyle} onClick={() => go('/messages')}>Messages</button>
          <div style={{ borderTop: '1px solid #333' }} />
          <button style={{ ...itemStyle, color: '#f44' }} onClick={handleLogout}>Log out</button>
        </div>
      )}
    </div>
  );
}

// Logo mark -- a guitar pick (plectrum) with an audio waveform cut through
// it. Ties both halves of what ToneTrade actually is: instruments, and the
// audio/video demo feature that's the project's main differentiator.
// Deliberately a solid silhouette with hard geometric strokes rather than
// stacked circles -- the previous mark turned into an indistinct blob at
// navbar size, which is the only size it's ever rendered at.
function LogoMark() {
  return (
    <svg width="40" height="40" viewBox="0 0 44 44" fill="none" aria-hidden="true">
      <path
        d="M22 4 C31 4 39 9.5 39 17.5 C39 26 30 37 22 40 C14 37 5 26 5 17.5 C5 9.5 13 4 22 4 Z"
        fill="var(--accent)"
      />
      <g stroke="#12140f" strokeWidth="2.6" strokeLinecap="round">
        <line x1="14" y1="19" x2="14" y2="24" />
        <line x1="18.7" y1="15" x2="18.7" y2="28" />
        <line x1="23.4" y1="11.5" x2="23.4" y2="31.5" />
        <line x1="28.1" y1="16" x2="28.1" y2="27" />
        <line x1="32.8" y1="19.5" x2="32.8" y2="23.5" />
      </g>
    </svg>
  );
}

function Navbar() {
  const navigate = useNavigate();
  const { user } = useAuth();

  return (
    <nav className="site-nav" style={{ padding: '12px 24px', color: 'white', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
      <div onClick={() => navigate('/')} style={{ cursor: 'pointer', display: 'flex', alignItems: 'center', gap: '10px' }}>
        <LogoMark />
        <div>
          <h1 style={{ margin: 0, fontSize: '22px', fontWeight: 700, letterSpacing: '-0.02em', lineHeight: 1 }}>
            Tone<span style={{ color: '#4caf50' }}>Trade</span>
          </h1>
          <p style={{ margin: '4px 0 0', fontSize: '11px', color: '#888', textTransform: 'uppercase', letterSpacing: '0.08em' }}>
            UK Secondhand Instrument &amp; Gear Marketplace
          </p>
        </div>
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
        {user ? (
          <>
            <button
              className="btn-primary"
              onClick={() => navigate('/create')}
              style={{ padding: '10px 20px', background: 'var(--accent)', color: 'white', border: 'none', borderRadius: '6px', cursor: 'pointer', fontSize: '14px', fontWeight: 600 }}
            >
              + Sell Gear
            </button>
            <AccountMenu username={user.username} userId={user.id} />
          </>
        ) : (
          <>
            <button
              className="btn-ghost"
              onClick={() => navigate('/login')}
              style={{ padding: '10px 16px', background: 'none', color: 'white', border: '1px solid #444', borderRadius: '6px', cursor: 'pointer', fontSize: '14px', fontWeight: 600 }}
            >
              Log in
            </button>
            <button
              className="btn-primary"
              onClick={() => navigate('/register')}
              style={{ padding: '10px 20px', background: 'var(--accent)', color: 'white', border: 'none', borderRadius: '6px', cursor: 'pointer', fontSize: '14px', fontWeight: 600 }}
            >
              Register
            </button>
          </>
        )}
      </div>
    </nav>
  );
}

export default Navbar;
