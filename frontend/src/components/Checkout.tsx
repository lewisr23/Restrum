import { useState, useEffect, useRef, useMemo, FormEvent } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { loadStripe, Stripe, Appearance } from '@stripe/stripe-js';
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';
import { useAuth } from '../context/AuthContext';
import { useTheme } from '../context/ThemeContext';

import { API, mediaUrl } from '../lib/config';

// Checkout, paid for without leaving the site.
//
// The card fields are Stripe's Payment Element, mounted into this page. That
// is a cross-origin iframe, so the numbers still go straight from the
// buyer's browser to Stripe and never touch Restrum, exactly as they did
// when this page redirected to a Stripe-hosted one. What changes is only
// where the buyer is standing while they type, which is the whole reason to
// do it: every marketplace a seller has used before keeps them on the site
// to pay, and being bounced to a third-party domain mid-purchase reads as
// less trustworthy rather than more.

/**
 * One Stripe.js instance per publishable key, for the lifetime of the tab.
 *
 * loadStripe injects a script tag, so calling it on every render would mean
 * a new one each time. The key arrives from the API rather than the bundle -
 * see CheckoutController for why - so this cannot simply live at module
 * scope with a constant.
 */
const clients = new Map<string, Promise<Stripe | null>>();

function stripeFor(key: string): Promise<Stripe | null> {
  if (!clients.has(key)) clients.set(key, loadStripe(key));

  return clients.get(key)!;
}

/**
 * Dress Stripe's iframe in the site's own palette.
 *
 * Read from the live custom properties rather than hardcoded, because the
 * Element cannot see our stylesheet across the iframe boundary and a second
 * hardcoded copy of the palette is a second thing to forget when the first
 * one changes. The fallbacks are only for a stylesheet that has not loaded.
 */
function appearance(theme: 'light' | 'dark'): Appearance {
  const css = getComputedStyle(document.documentElement);
  const token = (name: string, fallback: string) => css.getPropertyValue(name).trim() || fallback;

  return {
    // Stripe's own base theme has to change with ours. The variables below
    // only override what they name; everything Stripe draws and we do not
    // mention - a dropdown, an error icon, the tab strip between payment
    // methods - falls back to this, and 'night' on a white page is a black
    // box in the middle of the checkout.
    theme: theme === 'light' ? 'stripe' : 'night',
    variables: {
      colorPrimary: token('--accent', '#8c70f4'),
      colorBackground: token('--bg-card', '#1e1e1e'),
      colorText: token('--text', '#f2f2f2'),
      colorTextSecondary: token('--text-muted', '#9a9a9a'),
      colorDanger: token('--danger', '#f44336'),
      fontFamily: token('--font-body', 'system-ui, sans-serif'),
      borderRadius: token('--radius', '8px'),
    },
    rules: {
      '.Input': { borderColor: token('--border-input', '#444444') },
    },
  };
}

/**
 * The form itself, which has to be a child of <Elements> because that is
 * what useStripe and useElements read from.
 */
function PaymentForm({ orderId, price, postage, collectionOnly, total, sellerName, agreedOffer }: { orderId: number; price: string; postage: string; collectionOnly: boolean; total: string; sellerName: string; agreedOffer: boolean }) {
  const stripe = useStripe();
  const elements = useElements();
  const navigate = useNavigate();

  const [paying, setPaying] = useState(false);
  const [mounted, setMounted] = useState(false);
  const [problem, setProblem] = useState('');

  const submit = async (event: FormEvent) => {
    event.preventDefault();

    // Both are null until Stripe.js has finished loading. The button is
    // disabled until then, so this is belt and braces rather than a case
    // anyone should see.
    if (!stripe || !elements) return;

    setPaying(true);
    setProblem('');

    const { error } = await stripe.confirmPayment({
      elements,
      confirmParams: {
        // Only used by methods that genuinely have to leave the page, such
        // as a bank redirect. A card never gets here.
        return_url: `${window.location.origin}/orders/${orderId}?paid=1`,
      },
      // Leave the page only when the payment method demands it. A card that
      // needs a 3D Secure challenge gets a modal over this one instead of a
      // round trip, so the common case never navigates at all.
      redirect: 'if_required',
    });

    if (error) {
      // Card and validation errors are written for the buyer and say
      // something they can act on. Everything else is a fault on our side or
      // Stripe's, where the message can leak detail that helps nobody, so it
      // gets a line that at least answers the only question that matters.
      setProblem(
        error.type === 'card_error' || error.type === 'validation_error'
          ? error.message ?? 'That card was declined. Nothing has been charged.'
          : 'Something went wrong taking the payment. If you were not charged, please try again.',
      );
      setPaying(false);

      return;
    }

    // Either paid, or accepted and still settling. Which of the two it is
    // gets decided by the webhook rather than here, and the order page is
    // written to say so while it waits.
    navigate(`/orders/${orderId}?paid=1`);
  };

  return (
    <form className="checkout__layout" onSubmit={submit}>
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

        <h2 className="checkout__section-title">Payment details</h2>

        <div className="checkout__payment">
          <PaymentElement onReady={() => setMounted(true)} />
        </div>

        <div className="payment-note">
          <p>
            {collectionOnly
              ? `Arrange collection with ${sellerName} in chat once you have paid.`
              : `Postage is included in the total below, so ${sellerName} posts it to you once payment clears. Confirm your address with them in chat.`}
            {' '}If you have not confirmed after 14 days and have not told us there is a
            problem, the payment is released to the seller automatically.
          </p>
        </div>
      </div>

      <div className="order-summary">
        {/* Itemised rather than one figure. This used to show the item price
            labelled "Total", which was true only while postage did not
            exist; the moment carriage is charged, a single number is the
            one thing a buyer will dispute. */}
        <div className="order-summary__row">
          <span>
            Item
            {/* Named rather than left as a number that quietly differs from
                the listing. A buyer who agreed 400 on a 500 guitar should
                see why they are being charged 400, and a buyer who agreed
                nothing should never see this line at all. */}
            {agreedOffer && <span className="order-summary__note">Agreed with {sellerName}</span>}
          </span>
          <span>£{price}</span>
        </div>
        <div className="order-summary__row">
          <span>Postage</span>
          <span>
            {collectionOnly
              ? 'Collection'
              : Number(postage) === 0
                ? 'Free'
                : `£${postage}`}
          </span>
        </div>
        <div className="order-summary__row order-summary__row--total">
          <span>Total</span>
          <span className="order-summary__total-value">£{total}</span>
        </div>

        {problem && <div className="notice notice--error">{problem}</div>}

        <button
          type="submit"
          className="btn-primary btn-block btn-lg"
          disabled={!stripe || !mounted || paying}
        >
          {paying ? 'Taking payment...' : `Pay £${total}`}
        </button>

        <p className="order-summary__caveat">
          Your money is held by Restrum until you confirm the gear arrived as
          described.
        </p>
      </div>
    </form>
  );
}

function Checkout() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const { theme } = useTheme();

  const [listing, setListing] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const [payment, setPayment] = useState<{ order: any; clientSecret: string; key: string } | null>(null);
  const [problem, setProblem] = useState('');

  // Reserving is a write, and StrictMode runs effects twice in development.
  // Starting twice is harmless in itself, because the endpoint resumes an
  // existing reservation rather than making a second one, but it is a
  // pointless round trip and a confusing pair of lines in the log.
  const started = useRef(false);

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

  // Reserving on arrival rather than on a click, which is a deliberate change
  // from the redirect version. Getting to this page already means pressing
  // Buy on the listing, so the intent is not in doubt, and the alternative -
  // showing an empty panel with a button that fills it in - is a worse
  // version of the same commitment. The 30 minute reservation window and the
  // sweep behind it exist precisely so that arriving and then wandering off
  // costs the seller half an hour rather than the sale.
  useEffect(() => {
    if (!user || !listing || started.current) return;
    if (listing.status === 'SOLD' || user.id === listing.seller.id) return;

    started.current = true;

    (async () => {
      try {
        const res = await fetch(`${API}/api/listings/${id}/checkout`, {
          method: 'POST',
          headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` },
        });

        const body = await res.json().catch(() => null);

        if (res.ok) {
          // The ORDER is what the summary is drawn from, not the listing.
          // They can disagree: an accepted price offer is charged at the
          // agreed figure while the listing still asks its own price, and
          // the number beside the card fields has to be the one Stripe is
          // about to take.
          setPayment({
            order: body.order,
            clientSecret: body.client_secret,
            key: body.publishable_key,
          });

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
      }
    })();
  }, [id, user, listing]);

  // Rebuilt only when the secret or the key changes, because passing a fresh
  // options object on every render remounts the Element and throws away
  // whatever the buyer had typed into it.
  // Rebuilt when the theme changes as well as when the secret does, because
  // the Element is an iframe and cannot see our stylesheet: it is handed a
  // snapshot of the palette at mount, so switching themes mid-checkout would
  // otherwise leave the card fields in the old one.
  const elementsOptions = useMemo(
    () => (payment ? { clientSecret: payment.clientSecret, appearance: appearance(theme) } : null),
    [payment, theme],
  );

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

      <div className="checkout__item">
        {imgSrc ? (
          <img className="checkout__thumb" src={imgSrc} alt={listing.title} />
        ) : null}
        <div>
          <h2 className="checkout__item-title">{listing.title}</h2>
          <p className="checkout__item-seller">
            Sold by {listing.seller.username}
            {listing.seller.community_verified && <span className="checkout__verified">✓ Verified</span>}
          </p>
        </div>
      </div>

      {problem && <div className="notice notice--error">{problem}</div>}

      {!payment && !problem && (
        <div className="page text-muted">Setting up your payment...</div>
      )}

      {payment && elementsOptions && (
        <Elements stripe={stripeFor(payment.key)} options={elementsOptions}>
          <PaymentForm
            orderId={payment.order.id}
            price={payment.order.item_price}
            postage={payment.order.postage}
            collectionOnly={listing.collection_only}
            total={payment.order.amount}
            sellerName={listing.seller.username}
            agreedOffer={Boolean(payment.order.agreed_offer)}
          />
        </Elements>
      )}
    </div>
  );
}

export default Checkout;
