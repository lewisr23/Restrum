import { useState, useEffect, useCallback } from 'react';
import { useNavigate, useSearchParams, Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

import { API } from '../lib/config';

// Where a seller sets up getting paid.
//
// Restrum never asks for a bank account here. The button hands the seller to
// Stripe's own hosted onboarding, and what comes back is whether Stripe is
// willing to pay them. That is the whole page: everything sensitive happens
// somewhere this codebase cannot see.
function SellerPayments() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const { user } = useAuth();

  const [status, setStatus] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [starting, setStarting] = useState(false);
  const [problem, setProblem] = useState('');

  // Stripe returns the seller here when they finish, and sends them here with
  // stripe=refresh when the single-use onboarding link expired before they
  // used it. Both want a fresh read rather than the cached one, because the
  // account.updated webhook may be seconds behind the person.
  const returning = params.get('stripe') !== null;

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

  useEffect(() => {
    if (!user) { navigate('/login'); return; }
    load(returning);
  }, [user, navigate, load, returning]);

  const handleStart = async () => {
    if (!user) return;
    setStarting(true);
    setProblem('');
    try {
      const res = await fetch(`${API}/api/stripe/connect`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` },
      });
      const body = await res.json().catch(() => null);
      if (res.ok && body?.url) {
        window.location.href = body.url;
        return;
      }
      setProblem(body?.message || 'Could not start setting up payments. Try again shortly.');
    } catch {
      setProblem('Could not reach the server.');
    } finally {
      setStarting(false);
    }
  };

  if (loading) return <div className="page text-muted">Loading...</div>;

  const canSell = status?.can_sell;
  const started = status?.onboarded;

  return (
    <div className="seller-payments">
      <h1 className="seller-payments__title">Getting paid</h1>

      {problem && <div className="notice notice--error">{problem}</div>}

      <div className="panel">
        {canSell ? (
          <>
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
              <button className="btn-ghost" onClick={handleStart} disabled={starting}>
                {starting ? 'Opening Stripe...' : 'Update your details on Stripe'}
              </button>
            </div>
          </>
        ) : (
          <>
            <h2 className="seller-payments__state-title">
              {started ? 'Stripe still needs a few details' : 'Set up payments before you sell'}
            </h2>
            <p className="seller-payments__state-text">
              {started
                ? 'You started setting up with Stripe but it is not finished, so buyers cannot check out on your listings yet. Picking up where you left off takes a couple of minutes.'
                : 'Buyers pay Restrum by card, and we pass the money on to you once they confirm the gear arrived. To receive that, Stripe needs to verify who you are and where to pay.'}
            </p>

            <ul className="seller-payments__points">
              <li>Takes a few minutes, on Stripe's own secure pages.</li>
              <li>You'll need your address, date of birth and bank details.</li>
              <li>Restrum never sees your bank details.</li>
              <li>No HMRC or company registration needed to sell as an individual.</li>
            </ul>

            <button className="btn-primary btn-lg" onClick={handleStart} disabled={starting}>
              {starting ? 'Opening Stripe...' : started ? 'Finish setting up' : 'Set up payments with Stripe'}
            </button>
          </>
        )}
      </div>

      {started && !canSell && (
        <p className="seller-payments__footnote">
          Already finished on Stripe's side? Verification can take a few minutes.{' '}
          <button className="link-button" onClick={() => load(true)}>Check again</button>
        </p>
      )}
    </div>
  );
}

export default SellerPayments;
