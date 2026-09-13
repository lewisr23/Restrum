import { useState, useEffect } from 'react';
import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

import { API, mediaUrl } from '../lib/config';

// The order summary a buyer sees before they are handed over to Stripe.
//
// This page never touches card details. It reserves the listing, gets a
// Stripe Checkout URL back, and redirects: card data goes straight from the
// buyer's browser to Stripe and never passes through Restrum at all, which is
// the difference between taking payments and taking on card-data compliance.
function Checkout() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const { user } = useAuth();

  const [listing, setListing] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [starting, setStarting] = useState(false);
  const [problem, setProblem] = useState('');

  // Stripe sends a buyer who backed out to ?checkout=cancelled. Worth saying
  // out loud, because otherwise returning to this page looks like the button
  // simply did nothing.
  const cancelled = params.get('checkout') === 'cancelled';

  useEffect(() => {
    if (!user) { navigate('/login'); return; }
    fetch(`${API}/api/listings/${id}`, {
      headers: {
        Accept: 'application/json',
        ...(user?.token ? { Authorization: `Bearer ${user.token}` } : {}),
      },
    })
      .then(res => {
        if (!res.ok) throw new Error('Not found');
        return res.json();
      })
      .then(body => { setListing(body.data); setLoading(false); })
      .catch(() => { setError('Listing not found.'); setLoading(false); });
  }, [id, user, navigate]);

  const handlePay = async () => {
    if (!user || !listing) return;
    setStarting(true);
    setProblem('');

    try {
      const res = await fetch(`${API}/api/listings/${id}/checkout`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` },
      });

      const body = await res.json().catch(() => null);

      if (res.ok) {
        // A full page navigation rather than a router push: the destination
        // is Stripe's domain, not ours.
        window.location.href = body.checkout_url;
        return;
      }

      // 422 carries a specific reason - someone else is mid-checkout, the
      // seller has not finished setting up payments - and the specific
      // reason is the only useful thing to show. Anything else gets the
      // generic line, since the buyer can do nothing about it either way.
      setProblem(
        body?.errors?.listing?.[0]
        || body?.message
        || 'Something went wrong starting the payment. Nothing has been charged.',
      );
    } catch {
      setProblem('Could not reach the server. Nothing has been charged.');
    } finally {
      setStarting(false);
    }
  };

  if (loading) return <div className="page text-muted">Loading...</div>;
  if (error || !listing) return <div className="page text-error">{error || 'Listing not found.'}</div>;

  const imageUrl = listing.media?.find((m: any) => m.media_type === 'IMAGE')?.url ?? null;
  const imgSrc = imageUrl ? mediaUrl(imageUrl) : null;
  const isSeller = user?.id === listing.seller.id;
  const isSold = listing.status === 'SOLD';

  if (isSold) {
    return (
      <div className="checkout-outcome">
        <div className="checkout-outcome__panel">
          <h1 className="checkout-outcome__title">This listing has already sold</h1>
          <p className="checkout-outcome__text checkout-outcome__text--spaced">
            Someone got there first. {listing.title} is no longer available.
          </p>
          <button className="btn-primary" onClick={() => navigate('/')}>Browse more gear</button>
        </div>
      </div>
    );
  }

  if (isSeller) {
    return (
      <div className="checkout-outcome">
        <div className="checkout-outcome__panel">
          <h1 className="checkout-outcome__title">This is your own listing</h1>
          <p className="checkout-outcome__text checkout-outcome__text--spaced">
            You can't buy gear you're selling.
          </p>
          <button className="btn-ghost" onClick={() => navigate(`/listing/${id}`)}>Back to listing</button>
        </div>
      </div>
    );
  }

  return (
    <div className="checkout">
      <button className="back-link" onClick={() => navigate(`/listing/${id}`)}>Back to listing</button>

      <h1 className="checkout__title">Checkout</h1>

      {cancelled && (
        <div className="notice notice--muted">
          Payment cancelled, and nothing was charged. The listing is still held
          for you for a short while if you want another go.
        </div>
      )}

      {problem && <div className="notice notice--error">{problem}</div>}

      <div className="checkout__layout">
        <div className="panel">
          <h2 className="checkout__section-title">How paying works</h2>

          <ol className="protection-steps">
            <li className="protection-steps__step">
              <strong>You pay Restrum, not the seller.</strong> Your card is
              handled by Stripe. We never see the numbers.
            </li>
            <li className="protection-steps__step">
              <strong>We hold the money.</strong> The seller can see the sale
              and send the gear, but they are not paid yet.
            </li>
            <li className="protection-steps__step">
              <strong>You check the gear.</strong> When it arrives and it is
              what was described, you confirm it from your orders page.
            </li>
            <li className="protection-steps__step">
              <strong>Then the seller gets paid.</strong> If it never turns up,
              or it is not what was described, you have not lost your money.
            </li>
          </ol>

          <div className="payment-note">
            <p>
              Arrange collection or postage with {listing.seller.username} in
              chat once you have paid. If you have not confirmed after 14 days
              and have not told us there is a problem, the payment is released
              to the seller automatically.
            </p>
          </div>
        </div>

        <div className="order-summary">
          {imgSrc ? (
            <img className="order-summary__image" src={imgSrc} alt={listing.title} />
          ) : null}
          <p className="order-summary__seller">
            Sold by {listing.seller.username}
            {listing.seller.community_verified && <span className="order-summary__verified">✓ Verified</span>}
          </p>
          <h2 className="order-summary__title">{listing.title}</h2>
          <div className="order-summary__row">
            <span>Item price</span>
            <span>£{listing.price}</span>
          </div>
          <div className="order-summary__row order-summary__row--total">
            <span>Total</span>
            <span className="order-summary__total-value">£{listing.price}</span>
          </div>
          <button className="btn-primary btn-block btn-lg" onClick={handlePay} disabled={starting}>
            {starting ? 'Taking you to Stripe...' : 'Pay securely with Stripe'}
          </button>
          <p className="order-summary__caveat">
            You'll be taken to Stripe to pay. Your money is held until you
            confirm the gear arrived as described.
          </p>
        </div>
      </div>
    </div>
  );
}

export default Checkout;
