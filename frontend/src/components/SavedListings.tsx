import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import ListingCard from './ListingCard';

import { API } from '../lib/config';

interface SavedListingData {
  id: number;
  title: string;
  price: number;
  location: string;
  category: string;
  status: string;
  media?: { media_type: string; url: string }[];
}

function SavedListings() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const [listings, setListings] = useState<SavedListingData[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!user) { setLoading(false); return; }
    fetch(`${API}/api/listings/saved`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` },
    })
      .then(res => {
        if (!res.ok) throw new Error('Failed');
        return res.json();
      })
      // Paginated resource collection - the array is under .data, not the
      // bare response body.
      .then(body => { setListings(body.data); setLoading(false); })
      .catch(() => { setError('Could not load saved listings.'); setLoading(false); });
  }, [user]);

  if (!user) {
    return (
      <div style={{ padding: '24px', color: 'white' }}>
        <p>You need to <span style={{ color: '#4caf50', cursor: 'pointer' }} onClick={() => navigate('/login')}>log in</span> to see your saved listings.</p>
      </div>
    );
  }

  return (
    <div style={{ padding: '24px', color: 'white', maxWidth: '1300px', margin: '0 auto' }}>
      <h1 style={{ marginBottom: '24px' }}>Saved Listings</h1>
      {loading && <p style={{ color: '#888' }}>Loading...</p>}
      {error && <p style={{ color: '#f44' }}>{error}</p>}
      {!loading && !error && listings.length === 0 && (
        <p style={{ color: '#888' }}>Nothing saved yet — bookmark a listing from its page to see it here.</p>
      )}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '20px' }}>
        {listings.map(listing => (
          <ListingCard
            key={listing.id}
            id={listing.id}
            title={listing.title}
            price={listing.price}
            location={listing.location}
            category={listing.category}
            status={listing.status}
            imageUrl={listing.media?.find(m => m.media_type === 'IMAGE')?.url ?? null}
            audioUrls={listing.media?.filter(m => m.media_type === 'AUDIO').map(m => m.url)}
          />
        ))}
      </div>
    </div>
  );
}

export default SavedListings;
