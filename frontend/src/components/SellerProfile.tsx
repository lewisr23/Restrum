import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import ListingCard from './ListingCard';
import { PinIcon } from './Icon';

import { API } from '../lib/config';

interface SellerListing {
  id: number;
  title: string;
  price: number;
  location: string;
  category: { slug: string; path: string; name: string } | null;
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
  // What they have sold before. Deliberately absent from search results and
  // present here: on a profile it is the evidence a stranger came looking
  // for. Capped at twelve by the backend, with sold_count holding the real
  // total.
  sold_listings: SellerListing[];
  sold_count: number;
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
        // Sent even though the endpoint is public. If present and valid,
        // the backend uses it to fill in viewer_context for the viewer.
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
      // A single toggle endpoint, not separate POST and DELETE calls picked
      // by client state. It returns only {following: bool} with no updated
      // count, so the follower count is adjusted by exactly the one step this
      // toggle caused rather than fetched again.
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
          // response wasn't JSON, so stick with the status code
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
        // The endorse endpoint returns a fresh full profile, with counts,
        // community_verified and viewer_context all recomputed on the server,
        // so the simplest thing is to take that as the new state wholesale.
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

  if (loading) return <div className="page text-muted">Loading...</div>;
  if (error || !profile) return <div className="page text-error">{error || 'Seller not found.'}</div>;

  const isOwnProfile = user?.id === profile.id;
  const endorsed = profile.viewer_context?.have_i_endorsed;
  const canEndorse = profile.viewer_context?.can_endorse;
  const following = profile.viewer_context?.am_i_following;

  return (
    <div className="page">
      <button className="back-link" onClick={() => navigate(-1)}>Back</button>

      <div className="profile-header">
        <div className="profile-header__top">
          <div>
            <h1 className="profile-header__name">
              {profile.username}
              {profile.community_verified && (
                <span className="profile-header__verified">✓ Verified</span>
              )}
            </h1>
            {profile.location && (
              <p className="profile-header__location"><PinIcon /> {profile.location}</p>
            )}
            <p className="profile-header__stats">
              Member since {memberSinceLabel(profile.member_since)} · {profile.endorsement_count} endorsement{profile.endorsement_count === 1 ? '' : 's'} · {profile.follower_count} follower{profile.follower_count === 1 ? '' : 's'}
            </p>
          </div>

          {!isOwnProfile && (
            <div className="profile-header__actions">
              {user && profile.viewer_context && (
                <button
                  className={`btn-ghost endorse-btn${endorsed ? ' endorse-btn--done' : ''}${!endorsed && !canEndorse ? ' endorse-btn--locked' : ''}`}
                  onClick={handleEndorse}
                  disabled={endorseBusy || endorsed || !canEndorse}
                  title={!canEndorse && !endorsed ? 'You need to have messaged this person first' : undefined}
                >
                  {endorseBusy ? '...' : endorsed ? '✓ Endorsed' : 'Endorse'}
                </button>
              )}
              <button
                className={following ? 'btn-ghost' : 'btn-primary'}
                onClick={handleToggleFollow}
                disabled={followBusy}
              >
                {followBusy ? '...' : following ? 'Following ✓' : '+ Follow'}
              </button>
            </div>
          )}
        </div>
        {profile.bio && <p className="profile-header__bio">{profile.bio}</p>}
      </div>

      <h2 className="page__title">Listings from {profile.username}</h2>
      {profile.listings.length === 0 ? (
        <p className="text-muted">No listings yet.</p>
      ) : (
        <div className="listing-grid">
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

      {profile.sold_count > 0 && (
        <>
          <h2 className="page__title">
            Previously sold
            <span className="text-muted"> ({profile.sold_count})</span>
          </h2>
          <p className="text-muted">
            Gear {profile.username} has already sold through Restrum. Shown so you can see
            their history; these are not for sale.
          </p>
          <div className="listing-grid">
            {profile.sold_listings.map(listing => (
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
        </>
      )}
    </div>
  );
}

export default SellerProfile;
