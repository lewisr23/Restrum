import { useState, useRef, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

// Account dropdown. Consolidates "My Listings", Saved and Messages under one
// menu rather than loose navbar buttons, so the navbar doesn't keep growing
// sideways as more account pages get added.
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

  return (
    <div ref={menuRef} className="account-menu">
      <button
        className="account-menu__trigger"
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        aria-haspopup="true"
      >
        Hi, {username} <span className="account-menu__caret">{open ? '▲' : '▼'}</span>
      </button>

      {open && (
        <div className="account-menu__panel">
          <button className="account-menu__item" onClick={() => go(`/seller/${userId}`)}>My Listings</button>
          <button className="account-menu__item" onClick={() => go('/saved')}>Saved</button>
          <button className="account-menu__item" onClick={() => go('/messages')}>Messages</button>
          <div className="account-menu__divider" />
          <button className="account-menu__item account-menu__item--danger" onClick={handleLogout}>Log out</button>
        </div>
      )}
    </div>
  );
}

// Logo mark: a guitar pick with an audio waveform cut through it, tying
// together both halves of what ToneTrade is, instruments and the audio and
// video demos. A solid silhouette with hard strokes rather than stacked
// circles, because anything finer turns into a blob at navbar size, which is
// the only size it ever renders at.
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
    <nav className="site-nav">
      <div className="site-nav__brand" onClick={() => navigate('/')}>
        <LogoMark />
        <div>
          <h1 className="site-nav__title">
            Tone<span className="site-nav__title-accent">Trade</span>
          </h1>
          <p className="site-nav__tagline">UK Secondhand Instrument &amp; Gear Marketplace</p>
        </div>
      </div>

      <div className="site-nav__actions">
        {user ? (
          <>
            <button className="btn-primary" onClick={() => navigate('/create')}>+ Sell Gear</button>
            <AccountMenu username={user.username} userId={user.id} />
          </>
        ) : (
          <>
            <button className="btn-ghost" onClick={() => navigate('/login')}>Log in</button>
            <button className="btn-primary" onClick={() => navigate('/register')}>Register</button>
          </>
        )}
      </div>
    </nav>
  );
}

export default Navbar;
