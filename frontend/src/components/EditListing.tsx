import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

import { API, mediaUrl } from '../lib/config';
import { useCatalog } from '../lib/catalog';
import CategoryPicker from './CategoryPicker';
import CategoryFields from './CategoryFields';

// No POOR - the backend's condition enum is MINT/EXCELLENT/GOOD/FAIR only.
const conditionOptions = ['MINT', 'EXCELLENT', 'GOOD', 'FAIR'];

interface MediaItem {
  id: number;
  media_type: 'IMAGE' | 'AUDIO' | 'VIDEO';
  url: string;
  label: string | null;
}


// Same upload helper and pattern as CreateListing.tsx's uploadMedia: one
// multipart request per file, against a listing ID that already exists. Kept
// as a duplicate here rather than a shared import since these two
// components don't otherwise share a module and it's a small function.
async function uploadNewMedia(listingId: string, file: File, mediaType: 'IMAGE' | 'AUDIO' | 'VIDEO', token: string) {
  const formData = new FormData();
  formData.append('file', file);
  formData.append('media_type', mediaType);
  const res = await fetch(`${API}/api/listings/${listingId}/media`, {
    method: 'POST',
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    body: formData,
  });
  if (!res.ok) {
    // Carry the server's own sentence up rather than inventing one. A
    // rejected upload is usually a rule the seller can act on - too many
    // photos, wrong format, file too big - and "failed to upload" tells
    // them none of it. Laravel puts the useful text in errors.file; its
    // top-level message is the generic "The given data was invalid."
    const body = await res.json().catch(() => null);
    throw new Error(body?.errors?.file?.[0] ?? body?.message ?? 'Upload failed.');
  }
}

// Edits a listing's core fields (title/price/location/category/condition/
// description) and its media (photos/audio/video).
function EditListing() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const [form, setForm] = useState({
    title: '',
    price: '',
    location: '',
    condition: 'GOOD',
    description: '',
  });

  // Same split as CreateListing: the category drives which attribute fields
  // exist, so it cannot live in the plain text form state.
  const { catalog, error: catalogError } = useCatalog();
  const [category, setCategory] = useState<string | null>(null);
  const [brand, setBrand] = useState('');
  const [attributes, setAttributes] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notAllowed, setNotAllowed] = useState(false);
  const [uploadStatus, setUploadStatus] = useState('');

  const [existingMedia, setExistingMedia] = useState<MediaItem[]>([]);
  const [removingId, setRemovingId] = useState<number | null>(null);
  const [newImages, setNewImages] = useState<File[]>([]);
  const [newAudioFiles, setNewAudioFiles] = useState<File[]>([]);
  const [newVideoFiles, setNewVideoFiles] = useState<File[]>([]);

  useEffect(() => {
    fetch(`${API}/api/listings/${id}`, { headers: { Accept: 'application/json' } })
      .then(res => {
        if (!res.ok) throw new Error('Not found');
        return res.json();
      })
      // {data: {...listing (incl. media and a nested seller object)}} - there
      // is no separate "list media" endpoint on this backend, media always
      // comes embedded in the listing itself, so this one fetch covers both
      // the form fields and the existing-media list below.
      .then(body => {
        const listing = body.data;
        if (!user || user.id !== listing.seller.id) {
          setNotAllowed(true);
          setLoading(false);
          return;
        }
        setForm({
          title: listing.title,
          price: String(listing.price),
          location: listing.location,
          condition: listing.condition,
          description: listing.description || '',
        });
        setCategory(listing.category?.slug ?? null);
        setBrand(listing.brand || '');
        setAttributes(listing.attributes || {});
        setExistingMedia(listing.media || []);
        setLoading(false);
      })
      .catch(() => { setError('Listing not found.'); setLoading(false); });
  }, [id, user]);

  const handleRemoveMedia = async (mediaId: number) => {
    if (!user) return;
    if (!window.confirm('Remove this file from the listing?')) return;
    setRemovingId(mediaId);
    try {
      const res = await fetch(`${API}/api/listings/${id}/media/${mediaId}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` },
      });
      if (res.ok) {
        setExistingMedia(prev => prev.filter(m => m.id !== mediaId));
      } else {
        let detail = `Server responded ${res.status}`;
        try {
          const body = await res.json();
          detail = body?.message || detail;
        } catch {
          // response wasn't JSON, so stick with the status code
        }
        window.alert(`Couldn't remove this file: ${detail}`);
      }
    } catch (err) {
      window.alert('Could not reach the server. Is the backend running?');
    } finally {
      setRemovingId(null);
    }
  };

  if (!user) {
    return (
      <div className="page">
        <p>
          You need to <span className="link-inline" onClick={() => navigate('/login')}>log in</span> to edit a listing.
        </p>
      </div>
    );
  }

  if (loading) return <div className="page text-muted">Loading...</div>;
  if (notAllowed) return <div className="page">You can only edit your own listings.</div>;
  if (error) return <div className="page text-error">{error}</div>;

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    setForm({ ...form, [e.target.name]: e.target.value });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    try {
      const res = await fetch(`${API}/api/listings/${id}`, {
        method: 'PUT',
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
        setError('Failed to save changes.');
        setSaving(false);
        return;
      }

      // Text fields saved, so now upload any newly selected media against
      // the same listing, same best effort pattern as CreateListing: a
      // failed file doesn't block the rest of the save.
      const failed: string[] = [];
      const allUploads: { file: File; mediaType: 'IMAGE' | 'AUDIO' | 'VIDEO' }[] = [
        ...newImages.map(file => ({ file, mediaType: 'IMAGE' as const })),
        ...newAudioFiles.map(file => ({ file, mediaType: 'AUDIO' as const })),
        ...newVideoFiles.map(file => ({ file, mediaType: 'VIDEO' as const })),
      ];
      for (let i = 0; i < allUploads.length; i++) {
        const { file, mediaType } = allUploads[i];
        setUploadStatus(`Uploading ${i + 1}/${allUploads.length}: ${file.name}`);
        try {
          await uploadNewMedia(id!, file, mediaType, user.token);
        } catch (uploadError) {
          failed.push(`${file.name}: ${(uploadError as Error).message}`);
        }
      }
      setUploadStatus('');
      if (failed.length > 0) {
        window.alert('Changes saved, but these files were not uploaded:\n\n' + failed.join('\n'));
      }

      navigate(`/listing/${id}`);
    } catch {
      setError('Could not connect to server.');
      setSaving(false);
    }
  };

  const mediaTypeIcon: Record<string, string> = { IMAGE: '🖼️', AUDIO: '♪', VIDEO: '▶' };

  return (
    <div className="page page--form">
      <button className="back-link" onClick={() => navigate(`/listing/${id}`)}>← Back</button>
      <h1 className="page__title">Edit Listing</h1>

      <form className="form" onSubmit={handleSubmit}>
        <div className="field-group">
          <label className="field-label" htmlFor="title">Title *</label>
          <input className="field" id="title" name="title" value={form.title} onChange={handleChange} required />
        </div>

        <div className="field-group">
          <label className="field-label" htmlFor="price">Price (£) *</label>
          <input className="field" id="price" name="price" type="number" value={form.price} onChange={handleChange} required />
        </div>

        <div className="field-group">
          <label className="field-label" htmlFor="location">Location *</label>
          <input className="field" id="location" name="location" value={form.location} onChange={handleChange} required />
        </div>

        <div className="field-group">
          <label className="field-label">Category</label>
          {catalogError && <p className="text-error">{catalogError}</p>}
          {catalog ? (
            <CategoryPicker
              categories={catalog.categories}
              value={category}
              onChange={next => {
                setCategory(next);
                // Moving a listing to another category clears its old
                // answers, matching what the server does. Keeping a body
                // shape on something now filed under cables would put it in
                // a filter it does not belong to.
                setAttributes({});
              }}
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
          <textarea className="field field--textarea" id="description" name="description" value={form.description} onChange={handleChange} rows={4} />
        </div>

        <div className="media-manager">
          <h3 className="media-manager__heading">Media</h3>
          <p className="media-manager__intro">
            Remove existing photos, audio, or video, or add more below.
          </p>

          {existingMedia.length === 0 && (
            <p className="media-manager__intro">No media on this listing yet.</p>
          )}
          {existingMedia.length > 0 && (
            <div className="media-manager__list">
              {existingMedia.map(m => (
                <div key={m.id} className="media-manager__row">
                  {m.media_type === 'IMAGE' ? (
                    <img className="media-manager__thumb" src={mediaUrl(m.url)} alt="" />
                  ) : (
                    <span className="media-manager__icon">{mediaTypeIcon[m.media_type]}</span>
                  )}
                  <span className="media-manager__label">
                    {m.media_type.charAt(0) + m.media_type.slice(1).toLowerCase()}
                  </span>
                  <button
                    className="btn-danger btn-sm"
                    type="button"
                    onClick={() => handleRemoveMedia(m.id)}
                    disabled={removingId === m.id}
                  >
                    {removingId === m.id ? '...' : 'Remove'}
                  </button>
                </div>
              ))}
            </div>
          )}

          <div className="field-group">
            <label className="field-label" htmlFor="photos">Add photos</label>
            <input className="field field--file" id="photos" type="file" accept="image/*" multiple
              onChange={e => setNewImages(e.target.files ? Array.from(e.target.files) : [])} />
            <p className="field-hint">
              {newImages.length > 0 ? `${newImages.length} photo(s) selected` : 'Optional.'}
            </p>
          </div>

          <div className="field-group">
            <label className="field-label" htmlFor="audio">Add audio demo</label>
            <input className="field field--file" id="audio" type="file" accept="audio/*" multiple
              onChange={e => setNewAudioFiles(e.target.files ? Array.from(e.target.files) : [])} />
            <p className="field-hint">
              {newAudioFiles.length > 0 ? `${newAudioFiles.length} audio file(s) selected` : 'Optional.'}
            </p>
          </div>

          <div className="field-group">
            <label className="field-label" htmlFor="video">Add video demo</label>
            <input className="field field--file" id="video" type="file" accept="video/*" multiple
              onChange={e => setNewVideoFiles(e.target.files ? Array.from(e.target.files) : [])} />
            <p className="field-hint">
              {newVideoFiles.length > 0 ? `${newVideoFiles.length} video file(s) selected` : 'Optional.'}
            </p>
          </div>
        </div>

        {error && <p className="field-error">{error}</p>}
        {uploadStatus && <p className="form__status">{uploadStatus}</p>}

        <button className="btn-primary btn-block btn-lg" type="submit" disabled={saving}>
          {saving ? 'Saving...' : 'Save Changes'}
        </button>
      </form>
    </div>
  );
}

export default EditListing;
