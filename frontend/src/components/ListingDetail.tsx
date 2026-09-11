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
  // null = adding a new entry; set = editing an existing one in place (added
  // after a real typo made it into a logged entry with no way to fix it --
  // entries used to be add-only).
  const [editingEntryId, setEditingEntryId] = useState<number | null>(null);

  const fetchPassport = () => {
    fetch(`${API}/api/listings/${listingId}/passport`, { headers: { Accept: 'application/json' } })
      .then(res => res.json())
      // The endpoint wraps its response in {data: ...} (null when the
      // listing has no gear history yet) - unwrap it here once, rather than
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
        // whole passport (unlike the old backend) - simplest correct way to
        // get back to an accurate full list is to just re-fetch it.
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
          // response wasn't JSON -- stick with the status code
        }
        console.error('Failed to save gear history entry:', detail);
        alert(`Couldn't save this entry: ${detail}`);
      }
    } catch (err) {
      // This function previously had no try/catch at all -- a network
      // failure here would throw, skip the reset below entirely, and leave
      // the button permanently stuck on "Saving...".
      console.error('Failed to save gear history entry:', err);
      alert('Could not reach the server. Is the backend running?');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) return <p style={{ color: '#888', fontSize: '14px' }}>Loading...</p>;

  return (
    <div style={{ borderTop: '1px solid #333', paddingTop: '24px', marginTop: '32px' }}>
      <h3 style={{ margin: '0 0 4px', fontSize: '18px' }}>Gear History</h3>

      {passport?.serial_number && (
        <p style={{ color: '#888', fontSize: '13px', margin: '4px 0' }}>
          Serial: {passport.serial_number}
          {passport.year_manufactured && ` · Made: ${passport.year_manufactured}`}
        </p>
      )}

      {!passport || passport.entries.length === 0 ? (
        <p style={{ color: '#666', fontSize: '14px', margin: '16px 0' }}>No history entries yet.</p>
      ) : (
        <div style={{ marginTop: '16px' }}>
          {passport?.entries.map((entry, i) => (
            <div key={entry.id} style={{ display: 'flex', gap: '16px', marginBottom: '20px' }}>
              <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                <div style={{ width: '10px', height: '10px', borderRadius: '50%', background: '#4caf50', flexShrink: 0, marginTop: '4px' }} />
                {i < (passport?.entries.length ?? 0) - 1 && (
                  <div style={{ width: '2px', flex: 1, background: '#333', marginTop: '4px' }} />
                )}
              </div>
              <div style={{ paddingBottom: '8px' }}>
                <p style={{ margin: '0 0 2px', fontSize: '13px', color: '#4caf50', fontWeight: 600 }}>
                  {ENTRY_TYPE_LABELS[entry.entry_type] ?? entry.entry_type}
                  {entry.event_date && <span style={{ color: '#888', fontWeight: 400 }}> · {entry.event_date}</span>}
                </p>
                <p style={{ margin: 0, fontSize: '14px', color: '#ccc', lineHeight: '1.5' }}>{entry.description}</p>
                {isSeller && !(showForm && editingEntryId === entry.id) && (
                  <button
                    onClick={() => {
                      setForm({
                        entryType: entry.entry_type,
                        description: entry.description,
                        eventDate: entry.event_date ?? '',
                      });
                      setEditingEntryId(entry.id);
                      setShowForm(true);
                    }}
                    style={{ marginTop: '4px', padding: '2px 8px', background: 'none', border: '1px solid #333', color: '#888', borderRadius: '4px', cursor: 'pointer', fontSize: '11px' }}
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
          onClick={() => {
            setForm({ entryType: 'ORIGINAL_PURCHASE', description: '', eventDate: '' });
            setEditingEntryId(null);
            setShowForm(true);
          }}
          style={{ marginTop: '8px', padding: '8px 16px', background: 'none', border: '1px solid #444', color: '#aaa', borderRadius: '4px', cursor: 'pointer', fontSize: '13px' }}
        >
          + Add entry
        </button>
      )}

      {isSeller && showForm && (
        <form onSubmit={handleSubmit} style={{ marginTop: '16px', display: 'flex', flexDirection: 'column', gap: '12px', maxWidth: '400px' }}>
          <p style={{ margin: 0, fontSize: '12px', color: '#888' }}>
            {editingEntryId ? 'Editing entry' : 'New entry'}
          </p>
          <select
            value={form.entryType}
            onChange={e => setForm({ ...form, entryType: e.target.value })}
            style={{ padding: '8px', background: '#1a1a1a', border: '1px solid #444', color: 'white', borderRadius: '4px' }}
          >
            {Object.entries(ENTRY_TYPE_LABELS).map(([val, label]) => (
              <option key={val} value={val}>{label}</option>
            ))}
          </select>
          <textarea
            placeholder="Description"
            value={form.description}
            onChange={e => setForm({ ...form, description: e.target.value })}
            required
            rows={3}
            style={{ padding: '8px', background: '#1a1a1a', border: '1px solid #444', color: 'white', borderRadius: '4px', resize: 'vertical' }}
          />
          <input
            type="date"
            value={form.eventDate}
            onChange={e => setForm({ ...form, eventDate: e.target.value })}
            style={{ padding: '8px', background: '#1a1a1a', border: '1px solid #444', color: 'white', borderRadius: '4px' }}
          />
          <div style={{ display: 'flex', gap: '8px' }}>
            <button type="submit" disabled={submitting}
              style={{ padding: '8px 16px', background: '#4caf50', border: 'none', color: 'white', borderRadius: '4px', cursor: 'pointer' }}>
              {submitting ? 'Saving...' : editingEntryId ? 'Save changes' : 'Save'}
            </button>
            <button type="button" onClick={() => { setShowForm(false); setEditingEntryId(null); }}
              style={{ padding: '8px 16px', background: 'none', border: '1px solid #444', color: '#aaa', borderRadius: '4px', cursor: 'pointer' }}>
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

// Small rounded pill used for the location/condition/seller meta info —
// modelled loosely on Reverb's listing-page tags/seller box, kept in our own
// dark/grey palette rather than their light theme.
function MetaChip({ children }: { children: React.ReactNode }) {
  return (
    <span
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: '6px',
        padding: '6px 12px',
        borderRadius: '999px',
        background: '#1a1a1a',
        border: '1px solid #333',
        fontSize: '13px',
        color: '#ccc',
      }}
    >
      {children}
    </span>
  );
}

// Rough good-to-poor colour scale for the condition dot in its chip.
const CONDITION_COLORS: Record<string, string> = {
  MINT: '#4caf50',
  EXCELLENT: '#8bc34a',
  GOOD: '#cddc39',
  FAIR: '#ff9800',
  POOR: '#f44336',
};

// Fair price indicator — reads listing.price_insight, which the backend only
// attaches on the single-listing response (PriceInsightService, deliberately
// not run on the browse grid - see that class for why). Purely
// presentational: the comparison threshold lives server-side, one source of
// truth for what "fair" means.
const COMPARISON_STYLES: Record<string, { label: string; color: string; background: string }> = {
  below: { label: 'Below average', color: '#4caf50', background: 'rgba(76, 175, 80, 0.12)' },
  typical: { label: 'Fair price', color: '#8bc34a', background: 'rgba(139, 195, 74, 0.12)' },
  above: { label: 'Above average', color: '#ff9800', background: 'rgba(255, 152, 0, 0.12)' },
};

function PriceContext({ listing }: { listing: any }) {
  const insight = listing.price_insight;
  if (!insight || !insight.comparison) return null;

  const style = COMPARISON_STYLES[insight.comparison];
  if (!style) return null;

  const categoryLabel = formatCategory(listing.category);

  return (
    <div
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: '6px',
        marginTop: '4px',
        marginBottom: '12px',
        padding: '4px 10px',
        borderRadius: '4px',
        fontSize: '13px',
        color: style.color,
        background: style.background,
      }}
      title={`Average ${categoryLabel} listing price: £${insight.category_average} (based on ${insight.category_sample_size} listings)`}
    >
      {style.label} for {categoryLabel} · avg £{insight.category_average}
    </div>
  );
}

// Supplementary model-specific reference price - only rendered when the
// listing title matched a recognised model (PriceInsightService::matchReference).
function ReferencePrice({ listing }: { listing: any }) {
  const insight = listing.price_insight;
  if (!insight?.reference_label) return null;

  return (
    <p style={{ color: '#888', fontSize: '13px', margin: '0 0 8px' }}>
      Typical resale for {insight.reference_label}: ~£{insight.reference_price}{' '}
      <span style={{ color: '#666' }}>(rough estimate, not live market data)</span>
    </p>
  );
}

type MediaItem = { type: 'image' | 'video' | 'audio'; url: string };

// Backend sends one unified media array with a media_type discriminator
// (IMAGE/AUDIO/VIDEO), not three separately-named URL arrays like the old API.
function buildMediaItems(listing: any): MediaItem[] {
  const media: { media_type: string; url: string }[] = listing.media || [];
  const typeMap: Record<string, MediaItem['type']> = { IMAGE: 'image', VIDEO: 'video', AUDIO: 'audio' };
  return media
    .filter(m => typeMap[m.media_type])
    .map(m => ({ type: typeMap[m.media_type], url: m.url }));
}

function MediaThumb({ item, active, onClick }: { item: MediaItem; active: boolean; onClick: () => void }) {
  const baseStyle: React.CSSProperties = {
    width: '64px',
    height: '64px',
    borderRadius: '4px',
    cursor: 'pointer',
    border: active ? '2px solid #4caf50' : '1px solid #333',
    flexShrink: 0,
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    background: '#1a1a1a',
    color: '#888',
    fontSize: '20px',
    overflow: 'hidden',
  };

  if (item.type === 'image') {
    return (
      <img
        src={mediaUrl(item.url)}
        alt=""
        onClick={onClick}
        style={{ ...baseStyle, objectFit: 'cover' }}
      />
    );
  }

  return (
    <div onClick={onClick} style={baseStyle}>
      {item.type === 'video' ? '▶' : '♪'}
    </div>
  );
}

// Placeholder used when a listing has no media at all, so the dominant left
// column of the Reverb-style layout doesn't collapse to nothing.
function NoMediaPlaceholder() {
  return (
    <div
      style={{
        width: '100%',
        aspectRatio: '4 / 3',
        borderRadius: '8px',
        border: '1px solid #333',
        background: '#161616',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        color: '#555',
        fontSize: '14px',
      }}
    >
      No photos yet
    </div>
  );
}

// Single swipeable/scrollable media gallery covering photos, video, and audio
// together (eBay/Reverb-style) rather than three separately-headed, separately
// spaced blocks. Touch swipe is hand-rolled alongside click-driven prev/next
// arrows and thumbnails for desktop/mouse use.
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
    <div>
      <div
        onTouchStart={handleTouchStart}
        onTouchEnd={handleTouchEnd}
        style={{
          position: 'relative',
          width: '100%',
          borderRadius: '8px',
          border: '1px solid #333',
          overflow: 'hidden',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          background: '#111',
        }}
      >
        {active.type === 'image' && (
          <img
            src={mediaUrl(active.url)}
            alt={listing.title}
            style={{ width: '100%', maxHeight: '460px', objectFit: 'cover' }}
          />
        )}
        {active.type === 'video' && (
          <video key={active.url} controls style={{ width: '100%', maxHeight: '460px' }}>
            <source src={mediaUrl(active.url)} />
          </video>
        )}
        {active.type === 'audio' && (
          <div style={{ width: '100%', padding: '32px 24px' }}>
            <p style={{ color: '#888', fontSize: '13px', margin: '0 0 12px', textAlign: 'center' }}>Audio demo</p>
            <audio key={active.url} controls style={{ width: '100%' }}>
              <source src={mediaUrl(active.url)} />
            </audio>
          </div>
        )}

        {items.length > 1 && (
          <>
            <button
              onClick={() => goTo(activeIndex - 1)}
              aria-label="Previous"
              style={{ position: 'absolute', left: '8px', top: '50%', transform: 'translateY(-50%)', background: 'rgba(0,0,0,0.55)', border: 'none', color: 'white', borderRadius: '50%', width: '32px', height: '32px', cursor: 'pointer', fontSize: '16px', lineHeight: 1 }}
            >
              ‹
            </button>
            <button
              onClick={() => goTo(activeIndex + 1)}
              aria-label="Next"
              style={{ position: 'absolute', right: '8px', top: '50%', transform: 'translateY(-50%)', background: 'rgba(0,0,0,0.55)', border: 'none', color: 'white', borderRadius: '50%', width: '32px', height: '32px', cursor: 'pointer', fontSize: '16px', lineHeight: 1 }}
            >
              ›
            </button>
          </>
        )}
      </div>

      {items.length > 1 && (
        <div style={{ display: 'flex', gap: '8px', marginTop: '8px', overflowX: 'auto' }}>
          {items.map((item, i) => (
            <MediaThumb key={item.url + i} item={item} active={i === activeIndex} onClick={() => goTo(i)} />
          ))}
        </div>
      )}
    </div>
  );
}

// Right-hand "purchase panel" — title, condition, price, price context,
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
    <div>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '12px' }}>
        <p style={{ color: '#888', margin: 0, fontSize: '12px', textTransform: 'uppercase', letterSpacing: '0.06em' }}>
          {formatCategory(listing.category)}
        </p>
        {isSeller && (
          <button
            onClick={() => navigate(`/listing/${listing.id}/edit`)}
            style={{ background: 'none', border: '1px solid #444', color: '#aaa', padding: '4px 12px', borderRadius: '4px', cursor: 'pointer', fontSize: '12px', flexShrink: 0 }}
          >
            Edit listing
          </button>
        )}
      </div>
      <h1 style={{ margin: '8px 0', fontSize: '24px' }}>{listing.title}</h1>

      <MetaChip>
        <span
          style={{
            width: '8px',
            height: '8px',
            borderRadius: '50%',
            background: CONDITION_COLORS[listing.condition] || '#888',
            display: 'inline-block',
          }}
        />
        {formatCategory(listing.condition)}
      </MetaChip>

      <h2 style={{ color: '#4caf50', margin: '16px 0 0', fontSize: '28px' }}>
        £{listing.price}
        {isSold && (
          <span style={{ marginLeft: '12px', fontSize: '13px', fontWeight: 700, color: '#111', background: '#4caf50', padding: '3px 10px', borderRadius: '4px', verticalAlign: 'middle' }}>
            SOLD
          </span>
        )}
      </h2>
      <div>
        <PriceContext listing={listing} />
      </div>
      <ReferencePrice listing={listing} />

      {/* Unconditional rather than scoped to any particular id range: this
          is a demo/portfolio deployment, so it's equally true of a listing a
          visitor created themselves. */}
      <p style={{ marginTop: '16px', marginBottom: 0, padding: '10px 12px', background: '#241d0c', border: '1px solid #4a3d18', borderRadius: '6px', color: '#d8b463', fontSize: '13px', lineHeight: '1.5' }}>
        Demo listing — this item is not really for sale. Buy Now and offers are
        simulated; no payment is taken.
      </p>

      {!isSeller && !isSold && (
        <button
          className="btn-primary"
          onClick={onBuyNow}
          style={{ width: '100%', marginTop: '16px', padding: '14px 24px', background: 'var(--accent)', color: 'white', border: 'none', borderRadius: '6px', cursor: 'pointer', fontSize: '16px', fontWeight: 700 }}
        >
          {`Buy Now — £${listing.price}`}
        </button>
      )}
      {!isSeller && !isSold && !showMessageBox && (
        <button
          onClick={onToggleMessageBox}
          style={{ width: '100%', marginTop: '8px', padding: '12px 24px', background: 'none', color: '#ccc', border: '1px solid #444', borderRadius: '6px', cursor: 'pointer', fontSize: '14px', fontWeight: 600 }}
        >
          Message Seller instead
        </button>
      )}
      {!isSeller && !isSold && showMessageBox && (
        <div style={{ marginTop: '8px' }}>
          <textarea
            autoFocus
            placeholder="Ask the seller a question..."
            value={messageText}
            onChange={e => onMessageTextChange(e.target.value)}
            rows={3}
            style={{ width: '100%', padding: '10px', background: '#1a1a1a', border: '1px solid #444', color: 'white', borderRadius: '6px', resize: 'vertical', boxSizing: 'border-box', fontFamily: 'inherit' }}
          />
          <div style={{ display: 'flex', gap: '8px', marginTop: '6px' }}>
            <button
              onClick={onSendMessage}
              disabled={startingChat || !messageText.trim()}
              style={{ flex: 1, padding: '10px', background: 'var(--accent)', color: 'white', border: 'none', borderRadius: '6px', cursor: 'pointer', fontSize: '14px', fontWeight: 600 }}
            >
              {startingChat ? 'Sending...' : 'Send'}
            </button>
            <button
              onClick={onToggleMessageBox}
              style={{ padding: '10px 16px', background: 'none', color: '#aaa', border: '1px solid #444', borderRadius: '6px', cursor: 'pointer', fontSize: '14px' }}
            >
              Cancel
            </button>
          </div>
        </div>
      )}
      {!isSeller && isSold && (
        <p style={{ marginTop: '16px', color: '#666', fontSize: '14px' }}>This item has sold.</p>
      )}

      {!isSeller && (
        <button
          onClick={onToggleSave}
          disabled={saveBusy}
          style={{
            width: '100%',
            marginTop: '8px',
            padding: '12px 24px',
            background: 'none',
            color: listing.saved_by_viewer ? '#4caf50' : '#ccc',
            border: listing.saved_by_viewer ? '1px solid #4caf50' : '1px solid #444',
            borderRadius: '6px',
            cursor: 'pointer',
            fontSize: '14px',
            fontWeight: 600,
          }}
        >
          {saveBusy ? '...' : listing.saved_by_viewer ? '★ Saved' : '☆ Save for later'}
        </button>
      )}

      <div
        onClick={() => navigate(`/seller/${listing.seller.id}`)}
        style={{
          marginTop: '20px',
          padding: '16px',
          border: '1px solid #262626',
          borderRadius: '8px',
          background: '#161616',
          cursor: 'pointer',
        }}
      >
        <p style={{ margin: 0, fontSize: '14px', color: '#ddd' }}>
          Sold by <strong>{listing.seller.username}</strong>
          {listing.seller.community_verified && (
            <span style={{ color: '#4caf50', fontWeight: 700, marginLeft: '8px' }}>✓ Verified</span>
          )}
          <span style={{ color: '#666', marginLeft: '8px', fontSize: '13px' }}>View profile ›</span>
        </p>
        <p style={{ margin: '6px 0 0', fontSize: '13px', color: '#888' }}>📍 {listing.location}</p>
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
  // requires the actual first message in the same request - so this button
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
          // response wasn't JSON -- stick with the status code
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
      // current client-side state - the response's "saved" field is the
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
          // response wasn't JSON -- stick with the status code
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

  if (loading) return <div style={{ color: 'white', padding: '24px' }}>Loading...</div>;
  if (error || !listing) return <div style={{ color: 'white', padding: '24px' }}>{error || 'Listing not found.'}</div>;

  const isSeller = user?.id === listing.seller.id;
  const isSold = listing.status === 'SOLD';

  return (
    <div style={{ padding: '24px', color: 'white', maxWidth: '1100px' }}>
      <button
        onClick={() => navigate('/')}
        style={{ background: 'none', border: '1px solid #444', color: 'white', padding: '8px 16px', borderRadius: '4px', cursor: 'pointer', marginBottom: '24px' }}
      >
        Back
      </button>

      <div style={{ display: 'grid', gridTemplateColumns: '1.3fr 380px', gap: '40px', alignItems: 'start' }}>
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

      <div
        style={{
          background: '#161616',
          border: '1px solid #262626',
          borderRadius: '8px',
          padding: '16px',
          margin: '32px 0 0',
        }}
      >
        <p style={{ margin: '0 0 8px', fontSize: '13px', color: '#888', textTransform: 'uppercase', letterSpacing: '0.06em' }}>
          Description
        </p>
        <p style={{ margin: 0, lineHeight: '1.6', color: '#ddd' }}>{listing.description}</p>
      </div>

      <PassportSection listingId={id!} isSeller={isSeller} />
    </div>
  );
}

export default ListingDetail;
