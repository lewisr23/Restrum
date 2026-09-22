import { useState, useRef, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { usePublishedHeight } from '../lib/stickyHeights';
import { useAuth } from '../context/AuthContext';
import ThemeToggle from './ThemeToggle';
import NotificationBell from './NotificationBell';

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
          <button className="account-menu__item" onClick={() => go('/orders')}>Orders</button>
          <button className="account-menu__item" onClick={() => go('/saved')}>Saved</button>
          <button className="account-menu__item" onClick={() => go('/messages')}>Messages</button>
          <button className="account-menu__item" onClick={() => go('/sell/payments')}>Getting paid</button>
          <div className="account-menu__divider" />
          <button className="account-menu__item account-menu__item--danger" onClick={handleLogout}>Log out</button>
        </div>
      )}
    </div>
  );
}

// Logo mark: a plectrum with a single wave drawn through it.
//
// Two earlier versions are worth not repeating. The first stacked five bars
// inside a near-circular blob, which read as an audio player rather than a
// marketplace: a stack of bars on a coloured round shape is the shape every
// music app uses. The second had those bars centred on x=23.4 while the
// pick's own axis is x=22, and with heights 5/13/20/11/4 they were not
// symmetric either, so the whole thing sat visibly off.
//
// So: a real plectrum silhouette, flat shoulders and a proper tip rather
// than a circle, and one continuous wave instead of bars. The wave is three
// cubic segments approximating one and a half sine cycles, symmetric about
// x=22 and centred on y=21.9.
//
// scripts/generate-brand-assets.py draws the favicon, PWA icons and social
// card from these same numbers. Change them here and re-run it, or the tab
// icon and the navbar stop matching.
function LogoMark() {
  return (
    <svg width="40" height="40" viewBox="0 0 44 44" fill="none" aria-hidden="true">
      <path
        d="M22 3.5 C29.5 3.5 36.5 7 37.6 12.5 C38.8 18.5 32.5 32.5 22 40.8 C11.5 32.5 5.2 18.5 6.4 12.5 C7.5 7 14.5 3.5 22 3.5 Z"
        fill="var(--accent)"
      />
      <path
        d="M12.4 21.9 C14.53 14.7 16.67 14.7 18.8 21.9 C20.93 29.1 23.07 29.1 25.2 21.9 C27.33 14.7 29.47 14.7 31.6 21.9"
        stroke="#12140f"
        strokeWidth="2.9"
        strokeLinecap="round"
        fill="none"
      />
    </svg>
  );
}

function Navbar() {
  const navigate = useNavigate();
  const { user } = useAuth();

  // Everything else that sticks to the top of the window sits below this,
  // so its height has to be readable from CSS. See lib/stickyHeights.
  const navRef = usePublishedHeight<HTMLElement>('--nav-height');

  return (
    <nav className="site-nav" ref={navRef}>
      {/* An inner wrapper on the same container as every page below it, so
          the logo lines up with the hero text and the listing grid instead
          of floating out at the window edge on its own. */}
      <div className="site-nav__inner">
      <div className="site-nav__brand" onClick={() => navigate('/')}>
        <LogoMark />
        <div>
          <h1 className="site-nav__title">
            Re<span className="site-nav__title-accent">strum</span>
          </h1>
          <p className="site-nav__tagline">UK Secondhand Instrument &amp; Gear Marketplace</p>
        </div>
      </div>

      <div className="site-nav__actions">
        {/* Before the account controls rather than buried inside the menu:
            it is the one setting on the site, and someone who needs a light
            page needs it on the page they are looking at now. */}
        <ThemeToggle />

        {user ? (
          <>
            {/* Renders nothing for a signed-out visitor, so it sits inside
                the branch that already knows there is somebody to notify. */}
            <NotificationBell />

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
      </div>
    </nav>
  );
}

export default Navbar;
