import { useState, useEffect, useCallback } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

import { API, mediaUrl } from '../lib/config';
import { CategoryIcon } from './Icon';

const TABS = [
  { key: '', label: 'Everything' },
  { key: 'BUYER', label: 'Buying' },
  { key: 'SELLER', label: 'Selling' },
] as const;

// What each status means to the person reading it, which is not the same
// thing for the two sides of a sale: PAID is "your money is safe" to a buyer
// and "post it" to a seller.
function summarise(status: string, role: string | null): string {
  const buying = role === 'BUYER';

  switch (status) {
    case 'PENDING': return 'Waiting for payment';
    case 'PAID': return buying ? 'Paid, held until you confirm' : 'Paid, send it out';
    case 'CONFIRMED': return buying ? 'Confirmed, seller being paid' : 'Confirmed, payout on its way';
    case 'RELEASED': return buying ? 'Complete' : 'Paid out';
    case 'REFUNDED': return 'Refunded';
    case 'DISPUTED': return 'On hold';
    case 'CANCELLED': return 'Cancelled';
    default: return status;
  }
}

function Orders() {
  const navigate = useNavigate();
  const { user } = useAuth();

  const [role, setRole] = useState<string>('');
  const [orders, setOrders] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    if (!user) return;
    setLoading(true);
    try {
      const query = role ? `?role=${role}` : '';
      const res = await fetch(`${API}/api/orders${query}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` },
      });
      if (!res.ok) throw new Error('Failed');
      const body = await res.json();
      setOrders(body.data);
      setError('');
    } catch {
      setError('Could not load your orders.');
    } finally {
      setLoading(false);
    }
  }, [user, role]);

  useEffect(() => {
    if (!user) { navigate('/login'); return; }
    load();
  }, [user, navigate, load]);

  return (
    <div className="orders">
      <h1 className="orders__title">Your orders</h1>

      <div className="orders__tabs">
        {TABS.map(tab => (
          <button
            key={tab.key}
            className={`filter-pill${role === tab.key ? ' filter-pill--active' : ''}`}
            onClick={() => setRole(tab.key)}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {loading && <p className="text-muted">Loading...</p>}
      {error && <p className="text-error">{error}</p>}

      {!loading && !error && orders.length === 0 && (
        <div className="panel orders__empty">
          <p className="text-muted">
            Nothing here yet. Anything you buy or sell on Restrum shows up on
            this page, along with where the money has got to.
          </p>
          <Link className="btn-primary" to="/">Browse gear</Link>
        </div>
      )}

      <div className="orders__list">
        {orders.map(order => {
          const listing = order.listing;
          const imageUrl = listing?.media?.find((m: any) => m.media_type === 'IMAGE')?.url ?? null;
          const counterparty = order.viewer_role === 'BUYER' ? order.seller : order.buyer;

          return (
            <Link key={order.id} to={`/orders/${order.id}`} className="order-row">
              {imageUrl
                ? <img className="order-row__image" src={mediaUrl(imageUrl)} alt={listing?.title} />
                : <div className="order-row__image order-row__image--empty">
                    <CategoryIcon path={listing?.category?.path} size={26} />
                  </div>}

              <div className="order-row__body">
                <p className="order-row__meta">
                  {order.viewer_role === 'BUYER' ? 'Bought from' : 'Sold to'} {counterparty?.username}
                </p>
                <h2 className="order-row__title">{listing?.title ?? 'Listing removed'}</h2>
                <p className="order-row__status">{summarise(order.status, order.viewer_role)}</p>
              </div>

              <div className="order-row__amount">
                <span className="order-row__price">£{order.amount}</span>
                <span className={`order-status order-status--${order.status.toLowerCase()}`}>
                  {order.status.toLowerCase()}
                </span>
              </div>
            </Link>
          );
        })}
      </div>
    </div>
  );
}

export default Orders;
