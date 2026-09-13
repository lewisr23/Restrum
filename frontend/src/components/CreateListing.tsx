import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

import { API } from '../lib/config';
import { useCatalog } from '../lib/catalog';
import CategoryPicker from './CategoryPicker';
import CategoryFields from './CategoryFields';

// No POOR - the backend's condition enum is MINT/EXCELLENT/GOOD/FAIR only,
// one fewer step than the old API supported.
const conditionOptions = ['MINT', 'EXCELLENT', 'GOOD', 'FAIR'];

async function uploadMedia(listingId: number, file: File, mediaType: 'IMAGE' | 'AUDIO' | 'VIDEO', token: string) {
  const formData = new FormData();
  formData.append('file', file);
  formData.append('media_type', mediaType);
  const res = await fetch(`${API}/api/listings/${listingId}/media`, {
    method: 'POST',
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    body: formData,
  });
  if (!res.ok) throw new Error(`Failed to upload ${file.name}`);
}

function CreateListing() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const [form, setForm] = useState({
    title: '',
    price: '',
    location: '',
    condition: 'GOOD',
    description: '',
  });

  // Held apart from the rest of the form because they are not text inputs
  // and do not go through handleChange: the category decides which attribute
  // fields exist at all, and the attributes are a map rather than a field.
  const { catalog, error: catalogError } = useCatalog();
  const [category, setCategory] = useState<string | null>(null);
  const [brand, setBrand] = useState('');
  const [attributes, setAttributes] = useState<Record<string, string>>({});
  const [images, setImages] = useState<File[]>([]);
  const [audioFiles, setAudioFiles] = useState<File[]>([]);
  const [videoFiles, setVideoFiles] = useState<File[]>([]);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [uploadStatus, setUploadStatus] = useState('');

  // Whether Stripe will actually pay this seller. Asked here rather than only
  // at checkout because of who loses out otherwise: the listing goes up, a
  // buyer tries to pay, and the buyer is turned away by a problem the seller
  // was never told about and the buyer cannot do anything about.
  const [payoutReady, setPayoutReady] = useState<boolean | null>(null);

  useEffect(() => {
    if (!user) return;
    fetch(`${API}/api/stripe/connect`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` },
    })
      .then(res => (res.ok ? res.json() : null))
      .then(body => setPayoutReady(body ? Boolean(body.can_sell) : null))
      // A failed check must not stand between someone and writing a listing.
      // Null means unknown, and unknown shows nothing rather than a warning
      // that may well be wrong.
      .catch(() => setPayoutReady(null));
  }, [user]);

  if (!user) {
    return (
      <div className="page">
        <p>
          You need to <span className="link-inline" onClick={() => navigate('/login')}>log in</span> to create a listing.
        </p>
      </div>
    );
  }

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    setForm({ ...form, [e.target.name]: e.target.value });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    try {
      const res = await fetch(`${API}/api/listings`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${user.token}`,
        },
        body: JSON.stringify({
          title: form.title,
          description: form.description,
          price: parseFloat(form.price),
          location: form.location,
          category,
          brand: brand || null,
          condition: form.condition,
          attributes,
        }),
      });
      if (!res.ok) {
        setError('Failed to create listing.');
        setLoading(false);
        return;
      }
      // Wrapped in {data: {...listing}} by Laravel's JsonResource.
      const { data } = await res.json();

      // Listing exists now, so upload any media against it. Best effort: if
      // a file fails (too big, wrong type, network blip) we still take the
      // user to their new listing rather than stranding them on this form,
      // but we tell them what didn't make it.
      const failed: string[] = [];
      const allUploads: { file: File; mediaType: 'IMAGE' | 'AUDIO' | 'VIDEO' }[] = [
        ...images.map(file => ({ file, mediaType: 'IMAGE' as const })),
        ...audioFiles.map(file => ({ file, mediaType: 'AUDIO' as const })),
        ...videoFiles.map(file => ({ file, mediaType: 'VIDEO' as const })),
      ];

      for (let i = 0; i < allUploads.length; i++) {
        const { file, mediaType } = allUploads[i];
        setUploadStatus(`Uploading ${i + 1}/${allUploads.length}: ${file.name}`);
        try {
          await uploadMedia(data.id, file, mediaType, user.token);
        } catch {
          failed.push(file.name);
        }
      }
      setUploadStatus('');

      if (failed.length > 0) {
        // Still navigate. The listing was created successfully and media is
        // secondary, so just let them know before we leave.
        window.alert(`Listing posted, but these files failed to upload: ${failed.join(', ')}`);
      }

      navigate(`/listing/${data.id}`);
    } catch {
      setError('Could not connect to server.');
    }
    setLoading(false);
  };

  return (
    <div className="page page--form">
      <button className="back-link" onClick={() => navigate('/')}>← Back</button>
      <h1 className="page__title">Create a Listing</h1>

      {payoutReady === false && (
        <div className="notice notice--muted">
          <strong>Set up payments before anyone can buy this.</strong> Write
          the listing by all means, but buyers cannot check out on it until
          Stripe has verified you and knows where to pay you.{' '}
          <button type="button" className="link-button" onClick={() => navigate('/sell/payments')}>
            Set that up now
          </button>
        </div>
      )}

      <form className="form" onSubmit={handleSubmit}>
        <div className="field-group">
          <label className="field-label" htmlFor="title">Title *</label>
          <input className="field" id="title" name="title" value={form.title} onChange={handleChange} placeholder="e.g. Fender Stratocaster" required />
        </div>

        <div className="field-group">
          <label className="field-label" htmlFor="price">Price (£) *</label>
          <input className="field" id="price" name="price" type="number" value={form.price} onChange={handleChange} placeholder="e.g. 450" required />
        </div>

        <div className="field-group">
          <label className="field-label" htmlFor="location">Location *</label>
          <input className="field" id="location" name="location" value={form.location} onChange={handleChange} placeholder="e.g. Newcastle" required />
        </div>

        <div className="field-group">
          <label className="field-label">Category *</label>
          {catalogError && <p className="text-error">{catalogError}</p>}
          {catalog ? (
            <CategoryPicker
              categories={catalog.categories}
              value={category}
              onChange={setCategory}
            />
          ) : (
            <p className="text-muted">Loading categories...</p>
          )}
        </div>

        <CategoryFields
          categorySlug={category}
          brand={brand}
          attributes={attributes}
          onBrandChange={setBrand}
          onAttributeChange={(name, value) => setAttributes(prev => {
            const next = { ...prev };
            // An empty answer is removed rather than sent as a blank, so
            // "not stated" and "stated as nothing" cannot be confused.
            if (value === '') { delete next[name]; } else { next[name] = value; }
            return next;
          })}
        />

        <div className="field-group">
          <label className="field-label" htmlFor="condition">Condition</label>
          <select className="field field--select" id="condition" name="condition" value={form.condition} onChange={handleChange}>
            {conditionOptions.map(c => <option key={c} value={c}>{c.charAt(0) + c.slice(1).toLowerCase()}</option>)}
          </select>
        </div>

        <div className="field-group">
          <label className="field-label" htmlFor="description">Description</label>
          <textarea
            className="field field--textarea"
            id="description"
            name="description"
            value={form.description}
            onChange={handleChange}
            placeholder="Describe the item, condition, what's included..."
            rows={4}
          />
        </div>

        <div className="field-group">
          <label className="field-label" htmlFor="photos">Photos</label>
          <input className="field field--file" id="photos" type="file" accept="image/*" multiple
            onChange={e => setImages(e.target.files ? Array.from(e.target.files) : [])} />
          <p className="field-hint">
            {images.length > 0 ? `${images.length} photo(s) selected` : 'Optional, but listings with photos get more interest.'}
          </p>
        </div>

        <div className="field-group">
          <label className="field-label" htmlFor="audio">Audio demo</label>
          <input className="field field--file" id="audio" type="file" accept="audio/*" multiple
            onChange={e => setAudioFiles(e.target.files ? Array.from(e.target.files) : [])} />
          <p className="field-hint">
            {audioFiles.length > 0 ? `${audioFiles.length} audio file(s) selected` : 'A short clip proving it actually sounds good.'}
          </p>
        </div>

        <div className="field-group">
          <label className="field-label" htmlFor="video">Video demo</label>
          <input className="field field--file" id="video" type="file" accept="video/*" multiple
            onChange={e => setVideoFiles(e.target.files ? Array.from(e.target.files) : [])} />
          <p className="field-hint">
            {videoFiles.length > 0 ? `${videoFiles.length} video file(s) selected` : 'Show it being played, and show any cosmetic damage up close.'}
          </p>
        </div>

        {error && <p className="field-error">{error}</p>}
        {uploadStatus && <p className="form__status">{uploadStatus}</p>}

        <button className="btn-primary btn-block btn-lg" type="submit" disabled={loading}>
          {loading ? 'Posting...' : 'Post Listing'}
        </button>
      </form>
    </div>
  );
}

export default CreateListing;
