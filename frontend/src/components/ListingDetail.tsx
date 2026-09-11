import { useState, useEffect, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

import { API, mediaUrl } from '../lib/config';

interface PassportEntry {
  id: number;
  entry_type: string;
  description: string;
  event_date: string | null;
  created_at: string;
}

interface Passport {
  id: number;
  serial_number: string | null;
  year_manufactured: number | null;
  entries: PassportEntry[];
}

const ENTRY_TYPE_LABELS: Record<string, string> = {
  ORIGINAL_PURCHASE: 'Original Purchase',
  OWNERSHIP_CHANGE: 'Ownership Change',
  SERVICE: 'Service',
  REPAIR: 'Repair',
  MODIFICATION: 'Modification',
  OTHER: 'Other',
};

function PassportSection({ listingId, isSeller }: { listingId: string; isSeller: boolean }) {
  const { user } = useAuth();
  const [passport, setPassport] = useState<Passport | null>(null);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [form, setForm] = useState({ entryType: 'ORIGINAL_PURCHASE', description: '', eventDate: '' });
  // null = adding a new entry; set = editing an existing one in place, added
  // after a real typo made it into a logged entry with no way to fix it, since
  // entries could only ever be added before.
  const [editingEntryId, setEditingEntryId] = useState<number | null>(null);

  const fetchPassport = () => {
    fetch(`${API}/api/listings/${listingId}/passport`, { headers: { Accept: 'application/json' } })
      .then(res => res.json())
      // The endpoint wraps its response in {data: ...} (null when the
      // listing has no gear history yet), so unwrap it here once rather than
      // at every call site.
      .then(body => { setPassport(body.data); setLoading(false); })
      .catch(() => setLoading(false));
  };

  useEffect(fetchPassport, [listingId]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      const body: any = { entry_type: form.entryType, description: form.description };
      if (form.eventDate) body.event_date = form.eventDate;

      const url = editingEntryId
        ? `${API}/api/listings/${listingId}/passport/entries/${editingEntryId}`
        : `${API}/api/listings/${listingId}/passport/entries`;

      const res = await fetch(url, {
        method: editingEntryId ? 'PUT' : 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${user?.token}`,
        },
        body: JSON.stringify(body),
      });

      if (res.ok) {
        // The API returns just the one entry that was added/edited, not the
        // whole passport, so the simplest correct way back to an accurate
        // full list is to fetch it again.
        fetchPassport();
        setForm({ entryType: 'ORIGINAL_PURCHASE', description: '', eventDate: '' });
        setEditingEntryId(null);
        setShowForm(false);
      } else {
        let detail = `Server responded ${res.status}`;
        try {
          const errBody = await res.json();
          const firstFieldError = errBody?.errors ? (Object.values(errBody.errors)[0] as string[] | undefined)?.[0] : undefined;
          detail = firstFieldError || errBody?.message || detail;
        } catch {
          // response wasn't JSON, so stick with the status code
        }
        console.error('Failed to save gear history entry:', detail);
        alert(`Couldn't save this entry: ${detail}`);
      }
    } catch (err) {
      // This function previously had no try/catch at all. A network failure
      // here would throw, skip the reset below entirely, and leave the button
      // permanently stuck on "Saving...".
      console.error('Failed to save gear history entry:', err);
      alert('Could not reach the server. Is the backend running?');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) return <p className="text-muted">Loading...</p>;

  return (
    <div className="passport">
      <h3 className="passport__heading">Gear History</h3>

      {passport?.serial_number && (
        <p className="passport__serial">
          Serial: {passport.serial_number}
          {passport.year_manufactured && ` · Made: ${passport.year_manufactured}`}
        </p>
      )}

      {!passport || passport.entries.length === 0 ? (
        <p className="passport__empty">No history entries yet.</p>
      ) : (
        <div className="passport__timeline">
          {passport?.entries.map((entry, i) => (
            <div key={entry.id} className="passport__entry">
              <div className="passport__marker">
                <div className="passport__dot" />
                {i < (passport?.entries.length ?? 0) - 1 && <div className="passport__line" />}
              </div>
              <div className="passport__body">
                <p className="passport__type">
                  {ENTRY_TYPE_LABELS[entry.entry_type] ?? entry.entry_type}
                  {entry.event_date && <span className="passport__date"> · {entry.event_date}</span>}
                </p>
                <p className="passport__description">{entry.description}</p>
                {isSeller && !(showForm && editingEntryId === entry.id) && (
                  <button
                    className="passport__edit"
                    onClick={() => {
                      setForm({
                        entryType: entry.entry_type,
                        description: entry.description,
                        eventDate: entry.event_date ?? '',
                      });
                      setEditingEntryId(entry.id);
                      setShowForm(true);
                    }}
                  >
                    Edit
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      {isSeller && !showForm && (
        <button
          className="btn-ghost btn-sm passport__add"
          onClick={() => {
            setForm({ entryType: 'ORIGINAL_PURCHASE', description: '', eventDate: '' });
            setEditingEntryId(null);
            setShowForm(true);
          }}
        >
          + Add entry
        </button>
      )}

      {isSeller && showForm && (
        <form className="passport__form" onSubmit={handleSubmit}>
          <p className="passport__form-label">
            {editingEntryId ? 'Editing entry' : 'New entry'}
          </p>
          <select
            className="field field--select"
            value={form.entryType}
            onChange={e => setForm({ ...form, entryType: e.target.value })}
          >
            {Object.entries(ENTRY_TYPE_LABELS).map(([val, label]) => (
              <option key={val} value={val}>{label}</option>
            ))}
          </select>
          <textarea
            className="field field--textarea"
            placeholder="Description"
            value={form.description}
            onChange={e => setForm({ ...form, description: e.target.value })}
            required
            rows={3}
          />
          <input
            className="field"
            type="date"
            value={form.eventDate}
            onChange={e => setForm({ ...form, eventDate: e.target.value })}
          />
          <div className="passport__form-actions">
            <button className="btn-primary" type="submit" disabled={submitting}>
              {submitting ? 'Saving...' : editingEntryId ? 'Save changes' : 'Save'}
            </button>
            <button
              className="btn-ghost"
              type="button"
              onClick={() => { setShowForm(false); setEditingEntryId(null); }}
            >
              Cancel
            </button>
          </div>
        </form>
      )}
    </div>
  );
}


function formatCategory(cat: string) {
  return cat
    .toLowerCase()
    .split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

// Small rounded pill for the location, condition and seller meta info,
// modelled loosely on the tags and seller box on a Reverb listing page, kept
// in our own dark palette rather than their light one.
function MetaChip({ children }: { children: React.ReactNode }) {
  return <span className="chip">{children}</span>;
}

// Rough colour scale from good to poor for the condition dot in its chip.
const CONDITION_COLORS: Record<string, string> = {
  MINT: '#4caf50',
  EXCELLENT: '#8bc34a',
  GOOD: '#cddc39',
  FAIR: '#ff9800',
  POOR: '#f44336',
};

// Fair price indicator. Reads listing.price_insight, which the backend only
// attaches on the single listing response (PriceInsightService, deliberately
// not run on the browse grid; see that class for why). Purely presentational:
// the comparison threshold lives on the server, one source of truth for what
// "fair" means.
const COMPARISON_LABELS: Record<string, string> = {
  below: 'Below average',
  typical: 'Fair price',
  above: 'Above average',
};

function PriceContext({ listing }: { listing: any }) {
  const insight = listing.price_insight;
  if (!insight || !insight.comparison) return null;

  const label = COMPARISON_LABELS[insight.comparison];
  if (!label) return null;

  const categoryLabel = formatCategory(listing.category);

  return (
    <div
      className={`price-context price-context--${insight.comparison}`}
      title={`Average ${categoryLabel} listing price: £${insight.category_average} (based on ${insight.category_sample_size} listings)`}
    >
      {label} for {categoryLabel} · avg £{insight.category_average}
    </div>
  );
}

// Supplementary reference price for a specific model, rendered only when the
// listing title matched a recognised one (PriceInsightService::matchReference).
function ReferencePrice({ listing }: { listing: any }) {
  const insight = listing.price_insight;
  if (!insight?.reference_label) return null;

  return (
    <p className="reference-price">
      Typical resale for {insight.reference_label}: ~£{insight.reference_price}{' '}
      <span className="reference-price__caveat">(rough estimate, not live market data)</span>
    </p>
  );
}

type MediaItem = { type: 'image' | 'video' | 'audio'; url: string };

// The backend sends one unified media array with a media_type discriminator
// (IMAGE, AUDIO, VIDEO) rather than three separately named URL arrays.
function buildMediaItems(listing: any): MediaItem[] {
  const media: { media_type: string; url: string }[] = listing.media || [];
  const typeMap: Record<string, MediaItem['type']> = { IMAGE: 'image', VIDEO: 'video', AUDIO: 'audio' };
  return media
    .filter(m => typeMap[m.media_type])
    .map(m => ({ type: typeMap[m.media_type], url: m.url }));
}

function MediaThumb({ item, active, onClick }: { item: MediaItem; active: boolean; onClick: () => void }) {
  const className = `gallery__thumb${active ? ' gallery__thumb--active' : ''}`;

  if (item.type === 'image') {
    return <img className={className} src={mediaUrl(item.url)} alt="" onClick={onClick} />;
  }

  return (
    <div className={className} onClick={onClick}>
      {item.type === 'video' ? '▶' : '♪'}
    </div>
  );
}

// Shown when a listing has no media at all, so the dominant left column
// doesn't collapse to nothing.
function NoMediaPlaceholder() {
  return <div className="gallery__empty">No photos yet</div>;
}

// One swipeable gallery covering photos, video and audio together, in the
// style of eBay or Reverb, rather than three separately headed blocks. Touch
// swipe is written by hand alongside prev and next arrows and thumbnails for
// mouse use.
function MediaSection({ listing }: { listing: any }) {
  const items = buildMediaItems(listing);
  const [activeIndex, setActiveIndex] = useState(0);
  const touchStartX = useRef<number | null>(null);

  if (items.length === 0) return <NoMediaPlaceholder />;

  const active = items[activeIndex];

  const goTo = (i: number) => {
    setActiveIndex((i + items.length) % items.length);
  };

  const handleTouchStart = (e: React.TouchEvent) => {
    touchStartX.current = e.touches[0].clientX;
  };

  const handleTouchEnd = (e: React.TouchEvent) => {
    if (touchStartX.current === null) return;
    const delta = e.changedTouches[0].clientX - touchStartX.current;
    if (Math.abs(delta) > 40) {
      goTo(activeIndex + (delta < 0 ? 1 : -1));
    }
    touchStartX.current = null;
  };

  return (
    <div className="gallery">
      <div className="gallery__stage" onTouchStart={handleTouchStart} onTouchEnd={handleTouchEnd}>
        {active.type === 'image' && (
          <img className="gallery__image" src={mediaUrl(active.url)} alt={listing.title} />
        )}
        {active.type === 'video' && (
          <video className="gallery__video" key={active.url} controls>
            <source src={mediaUrl(active.url)} />
          </video>
        )}
        {active.type === 'audio' && (
          <div className="gallery__audio">
            <p className="gallery__audio-label">Audio demo</p>
            <audio className="gallery__audio-player" key={active.url} controls>
              <source src={mediaUrl(active.url)} />
            </audio>
          </div>
        )}

        {items.length > 1 && (
          <>
            <button
              className="gallery__arrow gallery__arrow--prev"
              onClick={() => goTo(activeIndex - 1)}
              aria-label="Previous"
            >
              ‹
            </button>
            <button
              className="gallery__arrow gallery__arrow--next"
              onClick={() => goTo(activeIndex + 1)}
              aria-label="Next"
            >
              ›
            </button>
          </>
        )}
      </div>
      {items.length > 1 && (
        <div className="gallery__thumbs">
          {items.map((item, i) => (
            <MediaThumb key={item.url + i} item={item} active={i === activeIndex} onClick={() => goTo(i)} />
          ))}
        </div>
      )}
    </div>
  );
}

// The "purchase panel" on the right: title, condition, price, price context,
// seller info, and the primary action. Modelled on Reverb's listing page
// layout, kept in our existing dark/grey palette.
function PurchasePanel({
  listing,
  isSeller,
  isSold,
  startingChat,
  saveBusy,
  showMessageBox,
  messageText,
  onMessageTextChange,
  onToggleMessageBox,
  onSendMessage,
  onToggleSave,
  onBuyNow,
}: {
  listing: any;
  isSeller: boolean;
  isSold: boolean;
  startingChat: boolean;
  saveBusy: boolean;
  showMessageBox: boolean;
  messageText: string;
  onMessageTextChange: (v: string) => void;
  onToggleMessageBox: () => void;
  onSendMessage: () => void;
  onToggleSave: () => void;
  onBuyNow: () => void;
}) {
  const navigate = useNavigate();

  return (
    <div className="purchase-panel">
      <div className="purchase-panel__top">
        <p className="purchase-panel__category">{formatCategory(listing.category)}</p>
        {isSeller && (
          <button
            className="btn-ghost btn-sm"
            onClick={() => navigate(`/listing/${listing.id}/edit`)}
          >
            Edit listing
          </button>
        )}
      </div>
      <h1 className="purchase-panel__title">{listing.title}</h1>

      <MetaChip>
        <span
          className="status-dot"
          style={{ color: CONDITION_COLORS[listing.condition] || 'currentColor' }}
        />
        {formatCategory(listing.condition)}
      </MetaChip>

      <h2 className="purchase-panel__price">
        £{listing.price}
        {isSold && <span className="purchase-panel__sold">SOLD</span>}
      </h2>
      <div>
        <PriceContext listing={listing} />
      </div>
      <ReferencePrice listing={listing} />

      {/* Unconditional rather than scoped to any particular id range: this
          is a demo deployment, so it's equally true of a listing a visitor
          created themselves. */}
      <p className="demo-notice">
        Demo listing: this item is not really for sale. Buy Now and offers are
        simulated, and no payment is taken.
      </p>

      {!isSeller && !isSold && (
        <button
          className="btn-primary btn-block btn-lg purchase-panel__action--primary"
          onClick={onBuyNow}
        >
          {`Buy Now for £${listing.price}`}
        </button>
      )}
      {!isSeller && !isSold && !showMessageBox && (
        <button className="btn-ghost btn-block purchase-panel__action" onClick={onToggleMessageBox}>
          Message Seller instead
        </button>
      )}
      {!isSeller && !isSold && showMessageBox && (
        <div className="purchase-panel__compose">
          <textarea
            className="field field--textarea"
            autoFocus
            placeholder="Ask the seller a question..."
            value={messageText}
            onChange={e => onMessageTextChange(e.target.value)}
            rows={3}
          />
          <div className="purchase-panel__compose-actions">
            <button
              className="btn-primary btn-block"
              onClick={onSendMessage}
              disabled={startingChat || !messageText.trim()}
            >
              {startingChat ? 'Sending...' : 'Send'}
            </button>
            <button className="btn-ghost" onClick={onToggleMessageBox}>Cancel</button>
          </div>
        </div>
      )}
      {!isSeller && isSold && (
        <p className="purchase-panel__sold-note">This item has sold.</p>
      )}

      {!isSeller && (
        <button
          className={`btn-ghost btn-block save-toggle${listing.saved_by_viewer ? ' save-toggle--saved' : ''}`}
          onClick={onToggleSave}
          disabled={saveBusy}
        >
          {saveBusy ? '...' : listing.saved_by_viewer ? '★ Saved' : '☆ Save for later'}
        </button>
      )}

      <div
        className="purchase-panel__seller"
        onClick={() => navigate(`/seller/${listing.seller.id}`)}
      >
        <p className="purchase-panel__seller-line">
          Sold by <strong>{listing.seller.username}</strong>
          {listing.seller.community_verified && (
            <span className="purchase-panel__seller-verified">✓ Verified</span>
          )}
          <span className="purchase-panel__seller-link">View profile ›</span>
        </p>
        <p className="purchase-panel__seller-location">📍 {listing.location}</p>
      </div>
    </div>
  );
}

function ListingDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const [listing, setListing] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [startingChat, setStartingChat] = useState(false);
  const [saveBusy, setSaveBusy] = useState(false);
  const [showMessageBox, setShowMessageBox] = useState(false);
  const [messageText, setMessageText] = useState('');

  useEffect(() => {
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
      // The show endpoint returns {data: {...listing}, price_insight: {...}}
      // - price_insight is a SIBLING of data, not nested inside it (Laravel's
      // JsonResource::additional() merges extra keys at the top level). Fold
      // it into one object here so the rest of this component can just read
      // listing.price_insight consistently.
      .then(body => { setListing({ ...body.data, price_insight: body.price_insight }); setLoading(false); })
      .catch(() => { setError('Listing not found.'); setLoading(false); });
  }, [id, user?.token]);

  // Unlike the old API (which created an empty conversation you then typed
  // into on the Messages page), this backend's start-a-conversation endpoint
  // requires the actual first message in the same request, so this button
  // now expands into a small compose box rather than immediately navigating.
  const handleSendMessage = async () => {
    if (!user) { navigate('/login'); return; }
    if (!messageText.trim()) return;
    setStartingChat(true);
    try {
      const res = await fetch(`${API}/api/listings/${id}/messages`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${user.token}`,
        },
        body: JSON.stringify({ content: messageText.trim() }),
      });
      if (res.ok) {
        const body = await res.json();
        navigate(`/messages/${body.conversation.id}`);
      } else {
        let detail = `Server responded ${res.status}`;
        try {
          const errBody = await res.json();
          detail = errBody?.message || detail;
        } catch {
          // response wasn't JSON, so stick with the status code
        }
        console.error('Failed to start conversation:', detail);
        alert(`Couldn't send that: ${detail}`);
      }
    } catch (err) {
      console.error('Failed to start conversation:', err);
      alert('Could not reach the server. Is the backend running?');
    } finally {
      setStartingChat(false);
    }
  };

  // Buy Now goes through the checkout page (order summary + confirm step)
  // rather than firing the purchase directly from here.
  const handleBuyNow = () => {
    if (!user) { navigate('/login'); return; }
    navigate(`/checkout/${id}`);
  };

  const handleToggleSave = async () => {
    if (!user) { navigate('/login'); return; }
    if (!listing) return;
    setSaveBusy(true);
    try {
      // One endpoint that toggles, rather than picking POST/DELETE based on
      // current client state. The response's "saved" field is the
      // authoritative result, not an assumption about what the toggle did.
      const res = await fetch(`${API}/api/listings/${id}/save`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` },
      });
      if (res.ok) {
        const body = await res.json();
        setListing({ ...listing, saved_by_viewer: body.saved });
      } else {
        let detail = `Server responded ${res.status}`;
        try {
          const errBody = await res.json();
          detail = errBody?.message || detail;
        } catch {
          // response wasn't JSON, so stick with the status code
        }
        console.error('Failed to toggle saved listing:', detail);
        alert(`Couldn't update saved status: ${detail}`);
      }
    } catch (err) {
      console.error('Failed to toggle saved listing:', err);
      alert('Could not reach the server. Is the backend running?');
    } finally {
      setSaveBusy(false);
    }
  };

  if (loading) return <div className="page text-muted">Loading...</div>;
  if (error || !listing) return <div className="page text-error">{error || 'Listing not found.'}</div>;

  const isSeller = user?.id === listing.seller.id;
  const isSold = listing.status === 'SOLD';

  return (
    <div className="listing-detail">
      <button className="back-link" onClick={() => navigate('/')}>Back</button>

      <div className="listing-detail__layout">
        <MediaSection listing={listing} />
        <PurchasePanel
          listing={listing}
          isSeller={isSeller}
          isSold={isSold}
          startingChat={startingChat}
          saveBusy={saveBusy}
          showMessageBox={showMessageBox}
          messageText={messageText}
          onMessageTextChange={setMessageText}
          onToggleMessageBox={() => setShowMessageBox(v => !v)}
          onSendMessage={handleSendMessage}
          onToggleSave={handleToggleSave}
          onBuyNow={handleBuyNow}
        />
      </div>

      <div className="listing-detail__description">
        <p className="listing-detail__description-label">Description</p>
        <p className="listing-detail__description-body">{listing.description}</p>
      </div>

      <PassportSection listingId={id!} isSeller={isSeller} />
    </div>
  );
}

export default ListingDetail;
