import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import ListingCard from './ListingCard';

import { API } from '../lib/config';

interface SellerListing {
  id: number;
  title: string;
  price: number;
  location: string;
  category: string;
  status: string;
  media?: { media_type: string; url: string }[];
}

interface SellerProfileData {
  id: number;
  username: string;
  community_verified: boolean;
  location: string | null;
  bio: string | null;
  member_since: string;
  endorsement_count: number;
  follower_count: number;
  listings: SellerListing[];
  // Absent entirely when viewing anonymously or viewing your own profile -
  // see UserProfileResource on the backend.
  viewer_context?: {
    am_i_following: boolean;
    have_i_endorsed: boolean;
    can_endorse: boolean;
  };
}

function memberSinceLabel(dateStr: string) {
  const date = new Date(dateStr);
  return date.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
}

function SellerProfile() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const [profile, setProfile] = useState<SellerProfileData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [followBusy, setFollowBusy] = useState(false);
  const [endorseBusy, setEndorseBusy] = useState(false);

  useEffect(() => {
    fetch(`${API}/api/users/${id}`, {
      headers: {
        Accept: 'application/json',
        // Sent even though the endpoint is public — if present and valid, the
        // backend uses it to fill in viewer_context for the logged-in viewer.
        ...(user?.token ? { Authorization: `Bearer ${user.token}` } : {}),
      },
    })
      .then(res => {
        if (!res.ok) throw new Error('Not found');
        return res.json();
      })
      .then(body => { setProfile(body.data); setLoading(false); })
      .catch(() => { setError('Seller not found.'); setLoading(false); });
  }, [id, user?.token]);

  const handleToggleFollow = async () => {
    if (!user) { navigate('/login'); return; }
    if (!profile) return;
    setFollowBusy(true);
    try {
      // A single toggle endpoint, not separate POST/DELETE calls picked by
      // client-side state - and it returns only {following: bool}, no
      // updated count, so the follower count is adjusted by exactly the ±1
      // this toggle just caused rather than re-fetched.
      const res = await fetch(`${API}/api/users/${id}/follow`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` },
      });
      if (res.ok) {
        const data = await res.json();
        setProfile({
          ...profile,
          follower_count: profile.follower_count + (data.following ? 1 : -1),
          viewer_context: profile.viewer_context
            ? { ...profile.viewer_context, am_i_following: data.following }
            : profile.viewer_context,
        });
      } else {
        let detail = `Server responded ${res.status}`;
        try {
          const body = await res.json();
          detail = body?.message || detail;
        } catch {
          // response wasn't JSON -- stick with the status code
        }
        console.error('Failed to toggle follow:', detail);
        alert(`Couldn't update follow status: ${detail}`);
      }
    } catch (err) {
      console.error('Failed to toggle follow:', err);
      alert('Could not reach the server. Is the backend running?');
    } finally {
      setFollowBusy(false);
    }
  };

  const handleEndorse = async () => {
    if (!user || !profile) return;
    setEndorseBusy(true);
    try {
      const res = await fetch(`${API}/api/users/${id}/endorse`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` },
      });
      if (res.ok) {
        const body = await res.json();
        // The endorse endpoint returns a fresh full profile (counts,
        // community_verified, viewer_context all recomputed server-side) -
        // simplest to just take that as the new state wholesale.
        setProfile(body.data);
      } else {
        let detail = `Server responded ${res.status}`;
        try {
          const errBody = await res.json();
          detail = errBody?.message || detail;
        } catch {
          // not JSON
        }
        alert(`Couldn't endorse: ${detail}`);
      }
    } catch {
      alert('Could not reach the server. Is the backend running?');
    } finally {
      setEndorseBusy(false);
    }
  };

  if (loading) return <div style={{ color: 'white', padding: '24px' }}>Loading...</div>;
  if (error || !profile) return <div style={{ color: 'white', padding: '24px' }}>{error || 'Seller not found.'}</div>;

  const isOwnProfile = user?.id === profile.id;

  return (
    <div style={{ padding: '24px', color: 'white', maxWidth: '1300px', margin: '0 auto' }}>
      <button
        onClick={() => navigate(-1)}
        style={{ background: 'none', border: '1px solid #444', color: 'white', padding: '8px 16px', borderRadius: '4px', cursor: 'pointer', marginBottom: '24px' }}
      >
        Back
      </button>

      <div
        style={{
          background: '#161616',
          border: '1px solid #262626',
          borderRadius: '8px',
          padding: '24px',
          marginBottom: '32px',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '16px', flexWrap: 'wrap' }}>
          <div>
            <h1 style={{ margin: '0 0 8px', display: 'flex', alignItems: 'center', gap: '10px' }}>
              {profile.username}
              {profile.community_verified && (
                <span style={{ fontSize: '13px', fontWeight: 700, color: '#4caf50' }}>✓ Verified</span>
              )}
            </h1>
            {profile.location && <p style={{ margin: '0 0 4px', color: '#aaa' }}>📍 {profile.location}</p>}
            <p style={{ margin: '0 0 12px', color: '#888', fontSize: '13px' }}>
              Member since {memberSinceLabel(profile.member_since)} · {profile.endorsement_count} endorsement{profile.endorsement_count === 1 ? '' : 's'} · {profile.follower_count} follower{profile.follower_count === 1 ? '' : 's'}
            </p>
          </div>

          {!isOwnProfile && (
            <div style={{ display: 'flex', gap: '8px', flexShrink: 0 }}>
              {user && profile.viewer_context && (
                <button
                  onClick={handleEndorse}
                  disabled={endorseBusy || profile.viewer_context.have_i_endorsed || !profile.viewer_context.can_endorse}
                  title={!profile.viewer_context.can_endorse && !profile.viewer_context.have_i_endorsed ? 'You need to have messaged this person first' : undefined}
                  style={{
                    padding: '10px 20px',
                    borderRadius: '6px',
                    cursor: profile.viewer_context.have_i_endorsed || !profile.viewer_context.can_endorse ? 'default' : 'pointer',
                    fontSize: '14px',
                    fontWeight: 600,
                    border: '1px solid #444',
                    background: 'none',
                    color: profile.viewer_context.have_i_endorsed ? '#4caf50' : '#ccc',
                    opacity: !profile.viewer_context.have_i_endorsed && !profile.viewer_context.can_endorse ? 0.5 : 1,
                  }}
                >
                  {endorseBusy ? '...' : profile.viewer_context.have_i_endorsed ? '✓ Endorsed' : 'Endorse'}
                </button>
              )}
              <button
                onClick={handleToggleFollow}
                disabled={followBusy}
                style={{
                  padding: '10px 20px',
                  borderRadius: '6px',
                  cursor: 'pointer',
                  fontSize: '14px',
                  fontWeight: 600,
                  flexShrink: 0,
                  border: profile.viewer_context?.am_i_following ? '1px solid #444' : 'none',
                  background: profile.viewer_context?.am_i_following ? 'none' : '#4caf50',
                  color: profile.viewer_context?.am_i_following ? '#ccc' : 'white',
                }}
              >
                {followBusy ? '...' : profile.viewer_context?.am_i_following ? 'Following ✓' : '+ Follow'}
              </button>
            </div>
          )}
        </div>
        {profile.bio && <p style={{ margin: '12px 0 0', lineHeight: '1.6', color: '#ddd' }}>{profile.bio}</p>}
      </div>

      <h2 style={{ fontSize: '18px', margin: '0 0 16px' }}>
        Listings from {profile.username}
      </h2>
      {profile.listings.length === 0 ? (
        <p style={{ color: '#888' }}>No listings yet.</p>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '20px' }}>
          {profile.listings.map(listing => (
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
      )}
    </div>
  );
}

export default SellerProfile;
