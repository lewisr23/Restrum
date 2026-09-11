import { useState, useEffect, useRef } from 'react';
import { BrowserRouter, Routes, Route } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import Navbar from './components/Navbar';
import ListingDetail from './components/ListingDetail';
import ListingCard from './components/ListingCard';
import CreateListing from './components/CreateListing';
import EditListing from './components/EditListing';
import Checkout from './components/Checkout';
import Login from './components/Login';
import Register from './components/Register';
import MessagesPage from './components/Messages';
import SellerProfile from './components/SellerProfile';
import SavedListings from './components/SavedListings';
import Footer from './components/Footer';

import { API } from './lib/config';

const categories = ["All", "Guitar", "Drums", "Microphone", "Synths", "Audio Equipment"];

const categoryToEnum: Record<string, string> = {
  "Guitar": "GUITAR",
  "Drums": "DRUMS",
  "Microphone": "MICROPHONE",
  "Synths": "SYNTHS",
  "Audio Equipment": "AUDIO_EQUIPMENT",
};

// Icon + short blurb per category tile on the homepage. Emoji instead of an
// icon library — deliberate: no new npm packages in this codebase without
// Lewis running the install himself (standing sandbox constraint).
const categoryTiles: { name: string; icon: string; blurb: string }[] = [
  { name: 'Guitar', icon: '🎸', blurb: 'Electric, acoustic & bass' },
  { name: 'Drums', icon: '🥁', blurb: 'Kits, snares & cymbals' },
  { name: 'Microphone', icon: '🎤', blurb: 'Studio & stage mics' },
  { name: 'Synths', icon: '🎹', blurb: 'Synths, keys & grooveboxes' },
  { name: 'Audio Equipment', icon: '🎚️', blurb: 'Interfaces, amps & pedals' },
];

function Hero({
  search,
  onSearch,
  onPickCategory,
  selectedCategory,
}: {
  search: string;
  onSearch: (v: string) => void;
  onPickCategory: (c: string) => void;
  selectedCategory: string;
}) {
  return (
    <div
      style={{
        background:
          'radial-gradient(ellipse 80% 60% at 20% 0%, rgba(76,175,80,0.12), transparent), radial-gradient(ellipse 60% 50% at 90% 10%, rgba(76,175,80,0.07), transparent), linear-gradient(180deg, #161616 0%, #121212 100%)',
        borderBottom: '1px solid var(--border)',
      }}
    >
      <div style={{ maxWidth: '1300px', margin: '0 auto', padding: '64px 24px 48px' }}>
        <h1
          style={{
            margin: 0,
            fontSize: 'clamp(32px, 5vw, 52px)',
            fontWeight: 700,
            letterSpacing: '-0.03em',
            lineHeight: 1.1,
            maxWidth: '720px',
          }}
        >
          Find your next instrument.{' '}
          <span style={{ color: 'var(--accent)' }}>Know its story.</span>
        </h1>
        <p style={{ margin: '16px 0 0', color: 'var(--text-muted)', fontSize: '17px', maxWidth: '560px', lineHeight: 1.6 }}>
          Secondhand gear from sellers across the UK — with real condition
          history, honest price context, and sellers vouched for by the people
          who've actually dealt with them.
        </p>

        <input
          type="text"
          className="hero-search"
          placeholder="Search gear — Stratocaster, SM58, OP-1..."
          value={search}
          onChange={e => onSearch(e.target.value)}
          style={{
            marginTop: '28px',
            padding: '16px 20px',
            width: '100%',
            maxWidth: '520px',
            display: 'block',
            background: 'var(--bg-card)',
            color: 'var(--text)',
            border: '1px solid var(--border-strong)',
            borderRadius: '12px',
            fontSize: '16px',
            boxSizing: 'border-box',
          }}
        />

        <div style={{ display: 'flex', gap: '24px', marginTop: '24px', flexWrap: 'wrap' }}>
          {['Gear history on every listing', 'Fair price context', 'Community-verified sellers'].map(t => (
            <span key={t} style={{ color: 'var(--text-muted)', fontSize: '13px', display: 'flex', alignItems: 'center', gap: '7px' }}>
              <span style={{ color: 'var(--accent)', fontWeight: 700 }}>✓</span> {t}
            </span>
          ))}
        </div>

        <div
          style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))',
            gap: '12px',
            marginTop: '40px',
          }}
        >
          {categoryTiles.map(tile => {
            const active = selectedCategory === tile.name;
            return (
              <button
                key={tile.name}
                className="cat-tile"
                onClick={() => onPickCategory(active ? 'All' : tile.name)}
                style={{
                  textAlign: 'left',
                  padding: '16px',
                  background: active ? '#1d2b1d' : 'var(--bg-card)',
                  border: active ? '1px solid var(--accent)' : '1px solid var(--border)',
                  borderRadius: 'var(--radius)',
                  cursor: 'pointer',
                  color: 'var(--text)',
                }}
              >
                <span style={{ fontSize: '24px', display: 'block' }}>{tile.icon}</span>
                <span style={{ display: 'block', marginTop: '10px', fontWeight: 600, fontSize: '14px', fontFamily: 'var(--font-display)' }}>
                  {tile.name}
                </span>
                <span style={{ display: 'block', marginTop: '3px', fontSize: '12px', color: 'var(--text-muted)' }}>
                  {tile.blurb}
                </span>
              </button>
            );
          })}
        </div>
      </div>
    </div>
  );
}

function HomePage() {
  const [search, setSearch] = useState('');
  const [selectedCategory, setSelectedCategory] = useState('All');
  // Price range filter — added 2026-07-28. Usability testing (P1-P4) showed
  // people expect to be able to narrow browsing by price, not just category
  // + free-text search; there was no way to do that before this. Kept as
  // plain strings (not numbers) so an empty input reads naturally as "no
  // bound" instead of coercing to 0.
  const [minPrice, setMinPrice] = useState('');
  const [maxPrice, setMaxPrice] = useState('');
  const [listings, setListings] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const gridRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const params = new URLSearchParams();
    if (search) params.set('search', search);
    if (selectedCategory !== 'All') params.set('category', categoryToEnum[selectedCategory]);
    if (minPrice) params.set('min_price', minPrice);
    if (maxPrice) params.set('max_price', maxPrice);

    setLoading(true);
    fetch(`${API}/api/listings?${params}`)
      .then(res => res.json())
      // Laravel's paginated resource collection wraps the array in
      // {data: [...], links: {...}, meta: {...}}, so the listings sit one
      // level deeper than the bare array this looks like it returns.
      .then(data => { setListings(data.data); setLoading(false); })
      .catch(() => { setError('Could not connect to backend.'); setLoading(false); });
  }, [search, selectedCategory, minPrice, maxPrice]);

  const pickCategory = (c: string) => {
    setSelectedCategory(c);
    // Bring the results into view when a tile is clicked from the hero
    setTimeout(() => gridRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
  };

  const activeCount = listings.filter(l => l.status !== 'SOLD').length;

  return (
    <div>
      <Hero
        search={search}
        onSearch={setSearch}
        onPickCategory={pickCategory}
        selectedCategory={selectedCategory}
      />

      <div ref={gridRef} style={{ maxWidth: '1300px', margin: '0 auto', padding: '40px 24px 64px', scrollMarginTop: '80px' }}>
        <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', flexWrap: 'wrap', gap: '12px', marginBottom: '20px' }}>
          <h2 style={{ margin: 0, fontSize: '22px', fontWeight: 700, letterSpacing: '-0.01em' }}>
            {selectedCategory === 'All' ? 'Latest gear' : selectedCategory}
            {!loading && !error && (
              <span style={{ marginLeft: '10px', fontSize: '14px', fontWeight: 500, color: 'var(--text-muted)', fontFamily: 'var(--font-body)' }}>
                {activeCount} for sale
              </span>
            )}
          </h2>
          <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center' }}>
            {categories.map(cat => (
              <button
                key={cat}
                onClick={() => setSelectedCategory(cat)}
                style={{
                  padding: '7px 14px',
                  background: selectedCategory === cat ? 'white' : 'var(--bg-card)',
                  color: selectedCategory === cat ? 'black' : 'var(--text)',
                  border: selectedCategory === cat ? 'none' : '1px solid var(--border)',
                  borderRadius: '999px',
                  cursor: 'pointer',
                  fontSize: '13px',
                  fontWeight: 600,
                }}
              >
                {cat}
              </button>
            ))}

            <div style={{ display: 'flex', alignItems: 'center', gap: '6px', marginLeft: '4px', paddingLeft: '12px', borderLeft: '1px solid var(--border)' }}>
              <span style={{ color: 'var(--text-muted)', fontSize: '13px' }}>£</span>
              <input
                type="number"
                min={0}
                placeholder="Min"
                value={minPrice}
                onChange={e => setMinPrice(e.target.value)}
                aria-label="Minimum price"
                style={{ width: '70px', padding: '6px 8px', background: 'var(--bg-card)', color: 'var(--text)', border: '1px solid var(--border)', borderRadius: '6px', fontSize: '13px' }}
              />
              <span style={{ color: 'var(--text-muted)', fontSize: '13px' }}>–</span>
              <input
                type="number"
                min={0}
                placeholder="Max"
                value={maxPrice}
                onChange={e => setMaxPrice(e.target.value)}
                aria-label="Maximum price"
                style={{ width: '70px', padding: '6px 8px', background: 'var(--bg-card)', color: 'var(--text)', border: '1px solid var(--border)', borderRadius: '6px', fontSize: '13px' }}
              />
              {(minPrice || maxPrice) && (
                <button
                  onClick={() => { setMinPrice(''); setMaxPrice(''); }}
                  style={{ padding: '6px 10px', background: 'none', color: 'var(--text-muted)', border: '1px solid var(--border)', borderRadius: '6px', cursor: 'pointer', fontSize: '12px' }}
                >
                  Clear
                </button>
              )}
            </div>
          </div>
        </div>

        {loading && <p style={{ color: 'var(--text-muted)' }}>Loading...</p>}
        {error && <p style={{ color: '#f44' }}>{error}</p>}
        {!loading && !error && listings.length === 0 && (
          <div style={{ padding: '48px 24px', textAlign: 'center', border: '1px dashed var(--border-strong)', borderRadius: 'var(--radius)' }}>
            <p style={{ margin: 0, fontSize: '15px', color: 'var(--text-muted)' }}>
              Nothing here yet{selectedCategory !== 'All' ? ` in ${selectedCategory}` : ''}{search ? ` matching “${search}”` : ''}.
            </p>
          </div>
        )}
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(230px, 1fr))', gap: '20px' }}>
          {listings.map(listing => (
            <ListingCard
              key={listing.id}
              id={listing.id}
              title={listing.title}
              price={listing.price}
              location={listing.location}
              category={listing.category}
              status={listing.status}
              imageUrl={listing.media?.find((m: any) => m.media_type === 'IMAGE')?.url ?? null}
              audioUrls={listing.media?.filter((m: any) => m.media_type === 'AUDIO').map((m: any) => m.url)}
            />
          ))}
        </div>
      </div>
    </div>
  );
}

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <div style={{ minHeight: '100vh', display: 'flex', flexDirection: 'column' }}>
          <Navbar />
          <div style={{ flex: 1 }}>
            <Routes>
              <Route path="/" element={<HomePage />} />
              <Route path="/listing/:id" element={<ListingDetail />} />
              <Route path="/listing/:id/edit" element={<EditListing />} />
              <Route path="/checkout/:id" element={<Checkout />} />
              <Route path="/seller/:id" element={<SellerProfile />} />
              <Route path="/saved" element={<SavedListings />} />
              <Route path="/create" element={<CreateListing />} />
              <Route path="/messages" element={<MessagesPage />} />
              <Route path="/messages/:id" element={<MessagesPage />} />
              <Route path="/login" element={<Login />} />
              <Route path="/register" element={<Register />} />
            </Routes>
          </div>
          <Footer />
        </div>
      </BrowserRouter>
    </AuthProvider>
  );
}

export default App;
