import { useNavigate } from 'react-router-dom';
import { useRef, useState } from 'react';

import { mediaUrl } from '../lib/config';

// The API sends the raw enum name (for example "AUDIO_EQUIPMENT"). Turn that
// into something presentable rather than showing the underscore to users.
function formatCategory(cat: string) {
  return cat
    .toLowerCase()
    .split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

// A placeholder per category, so listings with no photo still read as gear
// rather than as broken images. Matches the emoji set on the homepage tiles.
const CATEGORY_ICONS: Record<string, string> = {
  GUITAR: '🎸',
  DRUMS: '🥁',
  MICROPHONE: '🎤',
  SYNTHS: '🎹',
  AUDIO_EQUIPMENT: '🎚️',
  OTHER: '🎵',
};

// Play and pause for a listing's first audio demo, straight from the browse
// grid. Usability testing flagged wanting to hear a demo without opening
// every listing. stopPropagation on every handler is doing real work here:
// the whole card is a click target that navigates to the detail page.
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
        className="listing-card__audio-toggle"
        onClick={toggle}
        onMouseDown={e => e.stopPropagation()}
        aria-label={playing ? 'Pause audio demo' : 'Play audio demo'}
        title={playing ? 'Pause audio demo' : 'Play audio demo'}
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
      className={`listing-card${isSold ? ' listing-card--sold' : ''}`}
      onClick={() => navigate(`/listing/${id}`)}
    >
      {isSold && <span className="listing-card__badge">SOLD</span>}
      {previewAudioUrl && <AudioPreviewButton url={previewAudioUrl} />}

      {thumbSrc ? (
        <img className="listing-card__image" src={thumbSrc} alt={title} />
      ) : (
        <div className="listing-card__placeholder">{CATEGORY_ICONS[category] || '🎵'}</div>
      )}

      <div className="listing-card__body">
        <p className="listing-card__category">{formatCategory(category)}</p>
        <h3 className="listing-card__title">{title}</h3>
        <p className="listing-card__price">£{price}</p>
        <p className="listing-card__location">📍 {location}</p>
      </div>
    </div>
  );
}

export default ListingCard;
