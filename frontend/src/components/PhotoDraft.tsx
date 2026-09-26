import { useState, useEffect } from 'react';

import { API } from '../lib/config';

export type ListingDraft = {
  identified: boolean;
  title?: string;
  brand?: string | null;
  category?: string | null;
  attributes?: Record<string, string>;
  condition?: string | null;
  description?: string;
  price_low?: number | null;
  price_high?: number | null;
  serial_number?: string | null;
  notes?: string | null;
};

/** How many photos are sent. The first few show the most; more only costs. */
const MAX_PHOTOS = 3;

/**
 * Shrink a photo before it is sent.
 *
 * A phone photo is 4000px and several megabytes, and Claude reads images at
 * roughly 1568px on the long edge anyway, so sending the original is paying
 * to upload detail that is thrown away on arrival. Re-encoding as JPEG also
 * turns an iPhone's HEIC into something the API accepts, in any browser that
 * can decode HEIC at all.
 */
async function shrink(file: File): Promise<Blob> {
  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, 1568 / Math.max(bitmap.width, bitmap.height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(bitmap.width * scale);
  canvas.height = Math.round(bitmap.height * scale);
  canvas.getContext('2d')!.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  bitmap.close();

  return new Promise((resolve, reject) => {
    canvas.toBlob(blob => (blob ? resolve(blob) : reject(new Error('encode'))), 'image/jpeg', 0.85);
  });
}

/**
 * "Start from your photos": the seller picks photos, Claude fills in the form.
 *
 * The photos picked here are handed back as well as the draft, so they
 * become the listing's photos and the seller does not choose them twice.
 * Renders nothing at all when the server has no API key, so the sell form
 * never offers something that cannot work.
 */
function PhotoDraft({ token, onDraft }: { token: string; onDraft: (draft: ListingDraft, photos: File[]) => void }) {
  const [available, setAvailable] = useState(false);
  const [photos, setPhotos] = useState<File[]>([]);
  const [working, setWorking] = useState(false);
  const [problem, setProblem] = useState('');

  useEffect(() => {
    fetch(`${API}/api/listings/draft/status`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
      .then(res => (res.ok ? res.json() : null))
      .then(body => setAvailable(Boolean(body?.available)))
      .catch(() => setAvailable(false));
  }, [token]);

  if (!available) return null;

  const fill = async () => {
    setWorking(true);
    setProblem('');
    try {
      const body = new FormData();
      for (const photo of photos.slice(0, MAX_PHOTOS)) {
        body.append('photos[]', await shrink(photo), 'photo.jpg');
      }

      const res = await fetch(`${API}/api/listings/draft`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        body,
      });
      const json = await res.json().catch(() => null);

      if (res.status === 429) {
        setProblem('That is a lot of drafts in a short time. Give it a minute, or fill the form in yourself.');
      } else if (!res.ok || !json?.data) {
        setProblem(json?.message || 'Could not fill in the form from those photos. You can still write it yourself.');
      } else {
        onDraft(json.data, photos);
      }
    } catch {
      setProblem('Could not read one of those photos. Try a JPEG or PNG.');
    } finally {
      setWorking(false);
    }
  };

  return (
    <section className="photo-draft">
      <h2 className="photo-draft__title">Start from your photos</h2>
      <p className="photo-draft__lede">
        Add a few clear photos (the whole item, the logo or headstock, any
        model badge) and we will fill in the form for you to check. They
        become your listing photos too.
      </p>

      <input
        className="field field--file"
        type="file"
        accept="image/*"
        multiple
        aria-label="Photos to fill in the form from"
        onChange={e => setPhotos(e.target.files ? Array.from(e.target.files) : [])}
      />

      {problem && <p className="field-error">{problem}</p>}

      <button type="button" className="btn-primary" onClick={fill} disabled={photos.length === 0 || working}>
        {working ? 'Looking at your photos...' : 'Fill in from photos'}
      </button>
      {photos.length > MAX_PHOTOS && (
        <p className="field-hint">The first {MAX_PHOTOS} are used to fill in the form. All of them are added to the listing.</p>
      )}
    </section>
  );
}

export default PhotoDraft;
