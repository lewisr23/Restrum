import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

import { API, mediaUrl } from '../lib/config';

// Checkout page for the direct Buy Now flow. Deliberately does NOT process
// real payment. This is peer to peer, in the style of Gumtree or Facebook
// Marketplace: the platform records the sale, and buyer and seller arrange
// payment and collection between themselves through the messaging feature.
// This is a documented scope decision, not a missing feature: real card
// processing would need a payment processor integration, live card-data
// compliance, and webhook handling, all out of proportion for this project.
function Checkout() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();

  const [listing, setListing] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [buying, setBuying] = useState(false);
  const [purchased, setPurchased] = useState(false);
  // Collection preference is cosmetic context for the seller conversation.
  // It isn't persisted on the server, since no order entity exists and the
  // listing simply becomes SOLD.
  const [method, setMethod] = useState<'collection' | 'delivery'>('collection');

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

  const handleConfirm = async () => {
    if (!user || !listing) return;
    setBuying(true);
    try {
      const res = await fetch(`${API}/api/listings/${id}/buy`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` },
      });
      if (res.ok) {
        const body = await res.json();
        setListing(body.data);
        setPurchased(true);
      } else {
        let detail = `Server responded ${res.status}`;
        try {
          const body = await res.json();
          detail = body?.message || detail;
        } catch {
          // response wasn't JSON, so stick with the status code
        }
        alert(`Couldn't complete the purchase: ${detail}`);
      }
    } catch {
      alert('Could not reach the server. Is the backend running?');
    } finally {
      setBuying(false);
    }
  };

  // Unlike the old API, there's no "create an empty conversation" endpoint -
  // starting one requires sending an actual first message. Auto-sending a
  // sensible one here (rather than showing yet another text box right after
  // a purchase confirmation) is the most natural one-click way to get the
  // buyer and seller actually talking about collection/delivery.
  const openChatWithSeller = async () => {
    if (!user) return;
    const starter = method === 'collection'
      ? "Hi! Just bought this, what times work for me to come and collect it?"
      : "Hi! Just bought this, could we sort out delivery or postage?";
    try {
      const res = await fetch(`${API}/api/listings/${id}/messages`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${user.token}`,
        },
        body: JSON.stringify({ content: starter }),
      });
      if (res.ok) {
        const body = await res.json();
        navigate(`/messages/${body.conversation.id}`);
        return;
      }
      console.error('Could not open a chat with the seller:', await res.text());
    } catch (err) {
      console.error('Could not open a chat with the seller:', err);
    }
    // Only reached if that call genuinely failed. The inbox is still the best
    // place to land: the purchase itself already went through, so leaving the
    // buyer stranded on the checkout screen would be worse.
    navigate('/messages');
  };

  if (loading) return <div className="page text-muted">Loading...</div>;
  if (error || !listing) return <div className="page text-error">{error || 'Listing not found.'}</div>;

  const imageUrl = listing.media?.find((m: any) => m.media_type === 'IMAGE')?.url ?? null;
  const imgSrc = imageUrl ? mediaUrl(imageUrl) : null;
  const isSeller = user?.id === listing.seller.id;
  const isSold = listing.status === 'SOLD';

  // Already sold and we didn't just buy it here, so dead end politely.
  if (isSold && !purchased) {
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

  if (purchased) {
    return (
      <div className="checkout-outcome">
        <div className="checkout-outcome__panel checkout-outcome__panel--centred">
          <div className="checkout-outcome__icon">✅</div>
          <h1 className="checkout-outcome__title checkout-outcome__title--large">Purchase confirmed</h1>
          <p className="checkout-outcome__text">
            <strong className="checkout-outcome__strong">{listing.title}</strong> is yours for{' '}
            <strong className="checkout-outcome__price">£{listing.price}</strong>.
          </p>
          <p className="checkout-outcome__text checkout-outcome__text--spaced">
            Message {listing.seller.username} to arrange payment and{' '}
            {method === 'collection' ? 'collection' : 'delivery'}. This doesn't hold
            funds or process payment.
          </p>
          <div className="checkout-outcome__actions">
            <button className="btn-primary" onClick={openChatWithSeller}>
              Message {listing.seller.username}
            </button>
            <button className="btn-ghost" onClick={() => navigate('/')}>Back to browsing</button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="checkout">
      <button className="back-link" onClick={() => navigate(`/listing/${id}`)}>Back to listing</button>

      <h1 className="checkout__title">Checkout</h1>

      <div className="checkout__layout">
        <div className="panel">
          <h2 className="checkout__section-title">How you'll get it</h2>
          {([
            { key: 'collection', title: 'Collect in person', desc: `Meet the seller and pick it up, since they're in ${listing.location}. You can inspect the gear before handing anything over.` },
            { key: 'delivery', title: 'Arrange delivery', desc: 'Agree postage or a courier with the seller in chat. Check the gear on arrival.' },
          ] as const).map(opt => (
            <label
              key={opt.key}
              className={`delivery-option${method === opt.key ? ' delivery-option--selected' : ''}`}
            >
              <input
                className="delivery-option__radio"
                type="radio"
                name="method"
                checked={method === opt.key}
                onChange={() => setMethod(opt.key)}
              />
              <strong className="delivery-option__title">{opt.title}</strong>
              <p className="delivery-option__desc">{opt.desc}</p>
            </label>
          ))}

          <div className="payment-note">
            <p>
              <strong>Payment is arranged directly with the seller.</strong>{' '}
              This site doesn't hold funds or take a cut. Confirming reserves the
              listing for you and marks it sold, then you settle up in person or
              however you both agree in chat.
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
          <button className="btn-primary btn-block btn-lg" onClick={handleConfirm} disabled={buying}>
            {buying ? 'Confirming...' : 'Confirm purchase'}
          </button>
          <p className="order-summary__caveat">
            This can't be undone. The listing is marked sold immediately.
          </p>
        </div>
      </div>
    </div>
  );
}

export default Checkout;
