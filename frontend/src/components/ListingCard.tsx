import { useNavigate } from 'react-router-dom';
import { useRef, useState } from 'react';

import { mediaUrl } from '../lib/config';

// The API sends the raw enum name (e.g. "AUDIO_EQUIPMENT"). Turn that into
// something presentable rather than showing the underscore to users.
function formatCategory(cat: string) {
  return cat
    .toLowerCase()
    .split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

// Category-specific placeholder so photo-less listings still read as gear,
// not as broken images. Matches the emoji set used on the homepage tiles.
const CATEGORY_ICONS: Record<string, string> = {
  GUITAR: '🎸',
  DRUMS: '🥁',
  MICROPHONE: '🎤',
  SYNTHS: '🎹',
  AUDIO_EQUIPMENT: '🎚️',
  OTHER: '🎵',
};


// Play/pause button for previewing a listing's first audio demo straight
// from the browse grid, without opening the listing. Added 2026-07-28 —
// usability testing (P1-P4) flagged wanting to hear a demo without clicking
// into every listing. stopPropagation on every handler is load-bearing here:
// the whole card is a click target that navigates to the listing detail page.
function AudioPreviewButton({ url }: { url: string }) {
  const audioRef = useRef<HTMLAudioElement>(null);
  const [playing, setPlaying] = useState(false);

  const toggle = (e: React.MouseEvent) => {
    e.stopPropagation();
    const audio = audioRef.current;
    if (!audio) return;
    if (playing) {
      audio.pause();
    } else {
      audio.play();
    }
  };

  return (
    <>
      <button
        onClick={toggle}
        onMouseDown={e => e.stopPropagation()}
        aria-label={playing ? 'Pause audio demo' : 'Play audio demo'}
        title={playing ? 'Pause audio demo' : 'Play audio demo'}
        style={{
          position: 'absolute',
          bottom: '10px',
          right: '10px',
          width: '34px',
          height: '34px',
          borderRadius: '50%',
          border: 'none',
          background: 'rgba(0,0,0,0.65)',
          color: 'white',
          cursor: 'pointer',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          fontSize: '14px',
          zIndex: 2,
        }}
      >
        {playing ? '⏸' : '▶'}
      </button>
      <audio
        ref={audioRef}
        src={mediaUrl(url)}
        onPlay={() => setPlaying(true)}
        onPause={() => setPlaying(false)}
        onEnded={() => setPlaying(false)}
      />
    </>
  );
}

function ListingCard({ id, title, price, location, category, status, imageUrl, audioUrls }: { id: number, title: string, price: number, location: string, category: string, status?: string, imageUrl?: string | null, audioUrls?: string[] }) {
  const navigate = useNavigate();
  const isSold = status === 'SOLD';
  const thumbSrc = imageUrl ? mediaUrl(imageUrl) : null;
  const previewAudioUrl = audioUrls && audioUrls.length > 0 ? audioUrls[0] : null;

  return (
    <div
      className="listing-card"
      onClick={() => navigate(`/listing/${id}`)}
      style={{
        border: '1px solid var(--border)',
        borderRadius: 'var(--radius)',
        overflow: 'hidden',
        width: '100%',
        boxSizing: 'border-box',
        background: 'var(--bg-card)',
        color: 'var(--text)',
        cursor: 'pointer',
        opacity: isSold ? 0.55 : 1,
        position: 'relative',
        boxShadow: '0 2px 8px rgba(0,0,0,0.25)',
      }}
    >
      {isSold && (
        <span style={{ position: 'absolute', top: '10px', right: '10px', fontSize: '11px', fontWeight: 700, color: '#111', background: 'var(--accent)', padding: '2px 8px', borderRadius: '4px', zIndex: 1 }}>
          SOLD
        </span>
      )}
      {previewAudioUrl && <AudioPreviewButton url={previewAudioUrl} />}
      {thumbSrc ? (
        <img
          src={thumbSrc}
          alt={title}
          style={{ width: '100%', height: '170px', objectFit: 'cover', display: 'block' }}
        />
      ) : (
        <div style={{ width: '100%', height: '170px', background: 'linear-gradient(160deg, #232323, #1a1a1a)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '40px', opacity: 0.5 }}>
          {CATEGORY_ICONS[category] || '🎵'}
        </div>
      )}
      <div style={{ padding: '12px 14px 14px' }}>
        <p style={{ margin: '0 0 3px', fontSize: '11px', color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.06em', fontWeight: 600 }}>{formatCategory(category)}</p>
        <h3 style={{ margin: '0 0 6px', fontSize: '15px', fontWeight: 600, lineHeight: 1.35, overflow: 'hidden', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical' }}>{title}</h3>
        <p style={{ margin: '0 0 4px', fontSize: '18px', fontWeight: 700, color: 'var(--accent)', fontFamily: 'var(--font-display)' }}>£{price}</p>
        <p style={{ margin: 0, fontSize: '12px', color: 'var(--text-muted)' }}>📍 {location}</p>
      </div>
    </div>
  );
}

export default ListingCard;
