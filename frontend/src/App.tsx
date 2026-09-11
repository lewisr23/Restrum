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

// Icon plus short blurb per category tile on the homepage. Emoji instead of
// an icon library, deliberately: not worth a new npm dependency for a
// handful of glyphs.
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
    <div className="hero">
      <div className="hero__inner">
        <h1 className="hero__title">
          Find your next instrument.{' '}
          <span className="hero__title-accent">Know its story.</span>
        </h1>
        <p className="hero__lede">
          Secondhand gear from sellers across the UK, with real condition
          history, honest price context, and sellers vouched for by the people
          who've actually dealt with them.
        </p>

        <input
          type="text"
          className="hero-search hero__search"
          placeholder="Search gear: Stratocaster, SM58, OP-1..."
          value={search}
          onChange={e => onSearch(e.target.value)}
        />

        <div className="hero__points">
          {['Gear history on every listing', 'Fair price context', 'Sellers vouched for by the community'].map(t => (
            <span key={t} className="hero__point">
              <span className="hero__tick">✓</span> {t}
            </span>
          ))}
        </div>

        <div className="hero__categories">
          {categoryTiles.map(tile => {
            const active = selectedCategory === tile.name;
            return (
              <button
                key={tile.name}
                className={`cat-tile${active ? ' cat-tile--active' : ''}`}
                onClick={() => onPickCategory(active ? 'All' : tile.name)}
              >
                <span className="cat-tile__icon">{tile.icon}</span>
                <span className="cat-tile__name">{tile.name}</span>
                <span className="cat-tile__blurb">{tile.blurb}</span>
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
  // Price range filter, added 28 July 2026. Usability testing (P1 to P4)
  // showed people expect to narrow browsing by price, not just category and
  // free text search; there was no way to do that before. Kept as plain
  // strings rather than numbers, so an empty input reads naturally as "no
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

      <div ref={gridRef} className="browse">
        <div className="browse__header">
          <h2 className="browse__heading">
            {selectedCategory === 'All' ? 'Latest gear' : selectedCategory}
            {!loading && !error && (
              <span className="browse__count">{activeCount} for sale</span>
            )}
          </h2>
          <div className="browse__filters">
            {categories.map(cat => (
              <button
                key={cat}
                className={`filter-pill${selectedCategory === cat ? ' filter-pill--active' : ''}`}
                onClick={() => setSelectedCategory(cat)}
              >
                {cat}
              </button>
            ))}

            <div className="price-filter">
              <span className="price-filter__symbol">£</span>
              <input
                className="price-filter__input"
                type="number"
                min={0}
                placeholder="Min"
                value={minPrice}
                onChange={e => setMinPrice(e.target.value)}
                aria-label="Minimum price"
              />
              <span className="price-filter__separator">to</span>
              <input
                className="price-filter__input"
                type="number"
                min={0}
                placeholder="Max"
                value={maxPrice}
                onChange={e => setMaxPrice(e.target.value)}
                aria-label="Maximum price"
              />
              {(minPrice || maxPrice) && (
                <button
                  className="price-filter__clear"
                  onClick={() => { setMinPrice(''); setMaxPrice(''); }}
                >
                  Clear
                </button>
              )}
            </div>
          </div>
        </div>

        {loading && <p className="text-muted">Loading...</p>}
        {error && <p className="text-error">{error}</p>}
        {!loading && !error && listings.length === 0 && (
          <div className="browse__empty">
            Nothing here yet{selectedCategory !== 'All' ? ` in ${selectedCategory}` : ''}{search ? ` matching “${search}”` : ''}.
          </div>
        )}
        <div className="browse__grid">
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
        <div className="app-shell">
          <Navbar />
          <div className="app-shell__main">
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
