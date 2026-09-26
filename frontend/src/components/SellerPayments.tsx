import { useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { loadConnectAndInitialize, StripeConnectInstance, AppearanceOptions } from '@stripe/connect-js';
import {
  ConnectComponentsProvider,
  ConnectAccountOnboarding,
  ConnectAccountManagement,
  ConnectNotificationBanner,
  ConnectPayouts,
} from '@stripe/react-connect-js';
import { useAuth } from '../context/AuthContext';
import { useTheme } from '../context/ThemeContext';

import { API } from '../lib/config';

// Where a seller sets up getting paid.
//
// Restrum never asks for a bank account here. The form below is Stripe's own
// onboarding, mounted into this page as a cross-origin iframe, so identity
// and bank details go straight from the seller's browser to Stripe. This used
// to redirect to connect.stripe.com instead; it changed for the same reason
// checkout did, that being sent to another domain halfway through reads as
// less trustworthy, not more.
//
// Setting up happens entirely on this page, with no Stripe pop-up. Changing
// bank details afterwards does ask Stripe to text the seller a code, on
// purpose: see createOnboardingSession in StripePaymentGateway.

type Mode = 'setup' | 'manage';
type Session = { client_secret: string; publishable_key: string; mode: Mode };

async function requestSession(token: string): Promise<Session> {
  const res = await fetch(`${API}/api/stripe/connect`, {
    method: 'POST',
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  const body = await res.json().catch(() => null);
  if (!res.ok || !body?.client_secret) {
    throw new Error(body?.message || 'Could not start setting up payments. Try again shortly.');
  }

  return body;
}

/**
 * Dress Stripe's components in the site's own palette, read from the live
 * custom properties for the same reason Checkout does: the iframe cannot see
 * our stylesheet, and a hardcoded copy is a second palette to forget.
 */
function appearance(): AppearanceOptions {
  const css = getComputedStyle(document.documentElement);
  const token = (name: string, fallback: string) => css.getPropertyValue(name).trim() || fallback;

  return {
    overlays: 'dialog',
    variables: {
      colorPrimary: token('--accent', '#6446d0'),
      colorBackground: token('--bg-card', '#ffffff'),
      colorText: token('--text', '#18181b'),
      colorSecondaryText: token('--text-muted', '#67676f'),
      colorBorder: token('--border-input', '#b4b4bf'),
      colorDanger: token('--danger', '#c62828'),
      fontFamily: token('--font-body', 'Georgia, serif'),
      borderRadius: token('--radius', '10px'),
    },
  };
}

// The iframe cannot use the page's webfont unless it is told where to load it.
const FONTS = [{ cssSrc: 'https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400..600&display=swap' }];

function SellerPayments() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const { theme } = useTheme();

  const [status, setStatus] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [starting, setStarting] = useState(false);
  const [problem, setProblem] = useState('');
  const [connect, setConnect] = useState<StripeConnectInstance | null>(null);

  // Which kind of session the mounted components belong to. The server picks
  // it, and a setup session does not contain the payouts or bank details
  // components, so finishing setup means starting a new one.
  const [mode, setMode] = useState<Mode | null>(null);

  // Guards against opening two sessions at once, which StrictMode's double
  // effect would otherwise do in development.
  const opening = useRef(false);

  const load = useCallback(async (refresh: boolean) => {
    if (!user) return;
    try {
      const res = await fetch(`${API}/api/stripe/connect${refresh ? '?refresh=1' : ''}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` },
      });
      if (!res.ok) throw new Error('Failed');
      setStatus(await res.json());
      setProblem('');
    } catch {
      setProblem('Could not check your payment setup. Try again in a moment.');
    } finally {
      setLoading(false);
    }
  }, [user]);

  /**
   * Open a Stripe session and mount the components.
   *
   * The first secret arrives with the publishable key, so it is handed over
   * directly rather than fetched twice. After that Stripe.js calls back for
   * a fresh one whenever a session expires, and each of those is a new POST.
   */
  const open = useCallback(async () => {
    if (!user || opening.current) return;
    opening.current = true;
    setStarting(true);
    setProblem('');
    try {
      const first = await requestSession(user.token);
      let unused: string | null = first.client_secret;
      setMode(first.mode);

      // A first session is also what creates the Stripe account, so the
      // status read on page load is out of date from here on. Without this
      // the page still thinks nothing was started, and never offers to
      // check again.
      load(false);

      setConnect(loadConnectAndInitialize({
        publishableKey: first.publishable_key,
        fetchClientSecret: async () => {
          if (unused) {
            const secret = unused;
            unused = null;
            return secret;
          }
          return (await requestSession(user.token)).client_secret;
        },
        appearance: appearance(),
        fonts: FONTS,
      }));
    } catch (e) {
      setProblem(e instanceof Error ? e.message : 'Could not reach the server.');
      opening.current = false;
    } finally {
      setStarting(false);
    }
  }, [user, load]);

  useEffect(() => {
    if (!user) { navigate('/login'); return; }
    load(false);
  }, [user, navigate, load]);

  // A seller who already has an account goes straight to Stripe's form or
  // their payout details. One who has not must press the button first,
  // because opening a session is what creates their Stripe account, and
  // that should not happen to everyone who merely looks at this page.
  useEffect(() => {
    if (status?.onboarded && !connect) open();
  }, [status, connect, open]);

  // Setup just finished (or an account fell back to needing details): the
  // mounted session is the wrong kind, so drop it and let the effect above
  // open the right one.
  useEffect(() => {
    if (!connect || !status?.onboarded) return;
    const wanted: Mode = status.can_sell ? 'manage' : 'setup';
    if (mode !== wanted) {
      setConnect(null);
      setMode(null);
      opening.current = false;
    }
  }, [status, connect, mode]);

  // The palette is read once at initialisation, so a theme switch has to be
  // passed on. Deferred a frame so the new custom properties have applied.
  useEffect(() => {
    if (!connect) return;
    const frame = requestAnimationFrame(() => connect.update({ appearance: appearance() }));
    return () => cancelAnimationFrame(frame);
  }, [theme, connect]);

  if (loading) return <div className="page text-muted">Loading...</div>;

  const canSell = status?.can_sell;
  const started = status?.onboarded;

  return (
    <div className="seller-payments">
      <h1 className="seller-payments__title">Getting paid</h1>

      {problem && <div className="notice notice--error">{problem}</div>}

      {canSell ? (
        <>
          <div className="panel">
            <div className="seller-payments__state seller-payments__state--ready">
              <span className="seller-payments__tick">✓</span>
              <div>
                <h2 className="seller-payments__state-title">You're set up to sell</h2>
                <p className="seller-payments__state-text">
                  Stripe has verified you and money can reach your bank. When
                  someone buys your gear, Restrum holds their payment until they
                  confirm it arrived, then it is paid out to you automatically.
                </p>
              </div>
            </div>

            <div className="seller-payments__actions">
              <Link className="btn-primary" to="/create">List some gear</Link>
            </div>
          </div>

          {connect && (
            <ConnectComponentsProvider connectInstance={connect}>
              <div className="seller-payments__embed">
                <ConnectNotificationBanner />
              </div>

              <section className="seller-payments__section">
                <h2 className="seller-payments__section-title">Your payouts</h2>
                <div className="panel seller-payments__embed seller-payments__embed-panel">
                  <ConnectPayouts />
                </div>
              </section>

              <section className="seller-payments__section">
                <h2 className="seller-payments__section-title">Bank and contact details</h2>
                <p className="seller-payments__state-text">
                  Changing these asks Stripe to text you a code first, so
                  nobody who got into your Restrum account could redirect your
                  money.
                </p>
                <div className="panel seller-payments__embed seller-payments__embed-panel">
                  <ConnectAccountManagement />
                </div>
              </section>
            </ConnectComponentsProvider>
          )}
        </>
      ) : (
        <div className="panel">
          <h2 className="seller-payments__state-title">
            {started ? 'Stripe still needs a few details' : 'Set up payments before you sell'}
          </h2>
          <p className="seller-payments__state-text">
            {started
              ? 'You started setting up but it is not finished, so buyers cannot check out on your listings yet. Pick up where you left off below.'
              : 'Buyers pay Restrum by card, and we pass the money on to you once they confirm the gear arrived. To receive that, our payments partner Stripe needs to verify who you are and where to pay.'}
          </p>

          <ul className="seller-payments__points">
            <li>Takes a few minutes, right here on this page.</li>
            <li>You'll need your address, date of birth and bank details.</li>
            <li>The form is run by Stripe, our payments partner, so your details go straight to Stripe. Restrum never sees your bank details.</li>
            <li>No HMRC or company registration needed to sell as an individual.</li>
          </ul>

          {connect ? (
            <div className="seller-payments__embed seller-payments__embed--onboarding">
              <ConnectComponentsProvider connectInstance={connect}>
                <ConnectAccountOnboarding onExit={() => load(true)} />
              </ConnectComponentsProvider>
            </div>
          ) : (
            <button className="btn-primary btn-lg" onClick={open} disabled={starting}>
              {starting ? 'Loading...' : 'Set up payments'}
            </button>
          )}
        </div>
      )}

      {started && !canSell && (
        <p className="seller-payments__footnote">
          Finished the form? Verification can take a few minutes.{' '}
          <button className="link-button" onClick={() => load(true)}>Check again</button>
        </p>
      )}
    </div>
  );
}

export default SellerPayments;
