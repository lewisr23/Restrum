import { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate, useSearchParams, Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

import { API, mediaUrl } from '../lib/config';

// The stages an order moves through, in the order they happen. DISPUTED and
// CANCELLED are deliberately absent: they are not steps along this path, they
// are the path stopping, and drawing them as a stage would suggest otherwise.
const STAGES = [
  { key: 'PENDING', label: 'Payment started' },
  { key: 'PAID', label: 'Paid, held safely' },
  { key: 'CONFIRMED', label: 'You confirmed it arrived' },
  { key: 'RELEASED', label: 'Seller paid' },
];

const STAGE_ORDER = STAGES.map(s => s.key);

function statusLine(status: string, role: string | null): string {
  const you = role === 'BUYER';

  switch (status) {
    case 'PENDING':
      return you
        ? 'This order is waiting on payment. Nothing has been charged yet.'
        : 'A buyer has started checking out. Nothing is confirmed until they pay.';
    case 'PAID':
      return you
        ? 'Your money is being held by Restrum. Once the gear arrives and it is what was described, confirm below and the seller gets paid.'
        : 'The buyer has paid and Restrum is holding the money. Send the gear, and you are paid once the buyer confirms it arrived.';
    case 'CONFIRMED':
      return you
        ? 'Thanks. The seller is being paid.'
        : 'The buyer confirmed it arrived. Your payout is on its way to your Stripe account.';
    case 'RELEASED':
      return you
        ? 'All done. The seller has been paid.'
        : 'Paid out. The money is on its way to your bank via Stripe.';
    case 'REFUNDED':
      return you
        ? 'This order was refunded. The money is back on the card you paid with.'
        : 'This order was refunded to the buyer, and the listing is back up for sale.';
    case 'DISPUTED':
      return 'This order is on hold while a problem is sorted out. Nothing will be paid out until it is resolved.';
    case 'CANCELLED':
      return 'This order was cancelled before any payment was taken.';
    default:
      return '';
  }
}

function OrderDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const { user } = useAuth();

  const [order, setOrder] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [dispatching, setDispatching] = useState(false);
  const [carrier, setCarrier] = useState('');
  const [trackingNumber, setTrackingNumber] = useState('');
  const [problem, setProblem] = useState('');

  // Stripe sends the buyer back here with ?paid=1 the moment the card
  // clears, which is usually BEFORE the webhook has landed, so the order may
  // still read PENDING for a second or two. Saying "payment received" off the
  // back of the redirect alone would be trusting the browser about money.
  const justPaid = params.get('paid') === '1';

  const load = useCallback(async () => {
    if (!user) return;
    try {
      const res = await fetch(`${API}/api/orders/${id}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` },
      });
      if (!res.ok) throw new Error('Not found');
      const body = await res.json();
      setOrder(body.data);
    } catch {
      setError('That order could not be found.');
    } finally {
      setLoading(false);
    }
  }, [id, user]);

  useEffect(() => {
    if (!user) { navigate('/login'); return; }
    load();
  }, [user, navigate, load]);

  // One delayed re-read after arriving from Stripe, for exactly the gap
  // described above. Deliberately not a polling loop: if the webhook has not
  // arrived in a few seconds it is not going to help to keep asking, and the
  // order page is perfectly readable in its pending state.
  useEffect(() => {
    if (!justPaid || !order || order.status !== 'PENDING') return;
    const timer = setTimeout(load, 2500);
    return () => clearTimeout(timer);
  }, [justPaid, order, load]);

  const handleDispatch = async () => {
    if (!user || !order) return;
    setDispatching(true);
    setProblem('');
    try {
      const res = await fetch(`${API}/api/orders/${order.id}/dispatch`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${user.token}`,
        },
        body: JSON.stringify({
          tracking_carrier: carrier || null,
          tracking_number: trackingNumber || null,
        }),
      });
      const body = await res.json().catch(() => null);
      if (res.ok) {
        setOrder(body.data);
      } else {
        setProblem(body?.errors?.order?.[0] || body?.message || 'That did not work. Try again in a moment.');
      }
    } catch {
      setProblem('Could not reach the server.');
    } finally {
      setDispatching(false);
    }
  };

  const handleConfirm = async () => {
    if (!user || !order) return;
    setConfirming(true);
    setProblem('');
    try {
      const res = await fetch(`${API}/api/orders/${order.id}/confirm`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` },
      });
      const body = await res.json().catch(() => null);
      if (res.ok) {
        setOrder(body.data);
      } else {
        setProblem(body?.errors?.order?.[0] || body?.message || 'That did not work. Try again in a moment.');
      }
    } catch {
      setProblem('Could not reach the server.');
    } finally {
      setConfirming(false);
    }
  };

  if (loading) return <div className="page text-muted">Loading...</div>;
  if (error || !order) return <div className="page text-error">{error || 'Order not found.'}</div>;

  const listing = order.listing;
  const imageUrl = listing?.media?.find((m: any) => m.media_type === 'IMAGE')?.url ?? null;
  const imgSrc = imageUrl ? mediaUrl(imageUrl) : null;
  const isBuyer = order.viewer_role === 'BUYER';
  const counterparty = isBuyer ? order.seller : order.buyer;
  const stageIndex = STAGE_ORDER.indexOf(order.status);
  const stopped = order.status === 'REFUNDED' || order.status === 'CANCELLED' || order.status === 'DISPUTED';

  return (
    <div className="order-detail">
      <button className="back-link" onClick={() => navigate('/orders')}>Back to orders</button>

      <div className="order-detail__head">
        <h1 className="order-detail__title">Order #{order.id}</h1>
        <span className={`order-status order-status--${order.status.toLowerCase()}`}>
          {order.status.replace('_', ' ').toLowerCase()}
        </span>
      </div>

      {justPaid && order.status === 'PENDING' && (
        <div className="notice notice--muted">
          Thanks. We are just waiting for Stripe to confirm the payment, which
          usually takes a few seconds. You can safely leave this page.
        </div>
      )}

      {problem && <div className="notice notice--error">{problem}</div>}

      <div className="order-detail__layout">
        <div className="panel">
          <p className="order-detail__lede">{statusLine(order.status, order.viewer_role)}</p>

          {!stopped && (
            <ol className="order-progress">
              {STAGES.map((stage, i) => (
                <li
                  key={stage.key}
                  className={
                    'order-progress__step'
                    + (i < stageIndex ? ' order-progress__step--done' : '')
                    + (i === stageIndex ? ' order-progress__step--current' : '')
                  }
                >
                  {/* Ticked at i === stageIndex too, not just past it: the
                      current stage is one that has HAPPENED, so showing it as
                      a pending number reads as though it has not. */}
                  <span className="order-progress__marker">{i <= stageIndex ? '✓' : i + 1}</span>
                  <span className="order-progress__label">{stage.label}</span>
                </li>
              ))}
            </ol>
          )}

          {order.status === 'PAID' && order.viewer_role === 'SELLER' && !order.dispatched_at
            && !order.listing?.collection_only && (
            <div className="order-detail__action">
              <h2 className="checkout__section-title">Have you posted it?</h2>
              <p className="order-detail__action-text">
                Tell us when it is on its way. This is what starts the clock on your
                payout, and an order that is never marked as posted is refunded to the
                buyer, so do not leave it.
              </p>
              <div className="field-group">
                <label className="field-label" htmlFor="carrier">Carrier</label>
                <input
                  className="field"
                  id="carrier"
                  value={carrier}
                  onChange={e => setCarrier(e.target.value)}
                  placeholder="e.g. Royal Mail, Evri, DPD"
                />
              </div>
              <div className="field-group">
                <label className="field-label" htmlFor="tracking">Tracking number</label>
                <input
                  className="field"
                  id="tracking"
                  value={trackingNumber}
                  onChange={e => setTrackingNumber(e.target.value)}
                  placeholder="e.g. AB123456789GB"
                />
                <p className="field-hint">
                  Optional, but it is the thing that settles an argument about whether
                  the parcel arrived. Add it if the service gives you one.
                </p>
              </div>
              <button className="btn-primary btn-lg" onClick={handleDispatch} disabled={dispatching}>
                {dispatching ? 'Saving...' : 'Mark as posted'}
              </button>
            </div>
          )}

          {order.dispatched_at && (
            <div className="order-detail__tracking">
              <h2 className="checkout__section-title">On its way</h2>
              <p className="order-detail__action-text">
                Marked as posted on {new Date(order.dispatched_at).toLocaleDateString('en-GB')}
                {order.tracking_carrier ? ` via ${order.tracking_carrier}` : ''}.
              </p>
              {order.tracking_number && (
                <p className="order-detail__tracking-number">
                  Tracking: <strong>{order.tracking_number}</strong>
                </p>
              )}
            </div>
          )}

          {order.can_confirm && (
            <div className="order-detail__action">
              <h2 className="checkout__section-title">Has it arrived?</h2>
              <p className="order-detail__action-text">
                Only confirm once you have the gear in your hands and it is what
                the listing described. This pays the seller, and it cannot be
                undone.
              </p>
              <button className="btn-primary btn-lg" onClick={handleConfirm} disabled={confirming}>
                {confirming ? 'Confirming...' : 'Yes, it arrived as described'}
              </button>
              <p className="order-detail__action-note">
                Something wrong with it? Message {counterparty?.username} first.
                Most problems are sorted out between the two of you, and your
                money stays where it is in the meantime.
              </p>
            </div>
          )}
        </div>

        <div className="order-summary">
          {imgSrc ? <img className="order-summary__image" src={imgSrc} alt={listing?.title} /> : null}
          <p className="order-summary__seller">
            {isBuyer ? 'Sold by' : 'Bought by'} {counterparty?.username}
          </p>
          <h2 className="order-summary__title">
            {listing ? <Link to={`/listing/${listing.id}`}>{listing.title}</Link> : 'Listing removed'}
          </h2>

          <div className="order-summary__row">
            <span>{isBuyer ? 'You paid' : 'Sale price'}</span>
            <span>£{order.amount}</span>
          </div>

          {/* Shown to both sides. A buyer seeing the fee is the marketplace
              being open about what it takes; hiding it from the seller only
              makes their first payout a surprise. */}
          <div className="order-summary__row">
            <span>Restrum fee</span>
            <span>-£{order.platform_fee}</span>
          </div>

          <div className="order-summary__row order-summary__row--total">
            <span>{isBuyer ? 'Seller receives' : 'You receive'}</span>
            <span className="order-summary__total-value">£{order.seller_proceeds}</span>
          </div>

          <Link className="btn-ghost btn-block" to="/messages">
            Message {counterparty?.username}
          </Link>
        </div>
      </div>
    </div>
  );
}

export default OrderDetail;
