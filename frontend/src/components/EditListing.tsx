import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

import { API, mediaUrl } from '../lib/config';

const categoryToEnum: Record<string, string> = {
  'Guitar': 'GUITAR',
  'Drums': 'DRUMS',
  'Microphone': 'MICROPHONE',
  'Synths': 'SYNTHS',
  'Audio Equipment': 'AUDIO_EQUIPMENT',
};
const enumToCategory: Record<string, string> = Object.fromEntries(
  Object.entries(categoryToEnum).map(([label, val]) => [val, label])
);

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
  if (!res.ok) throw new Error(`Failed to upload ${file.name}`);
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
    category: 'Guitar',
    condition: 'GOOD',
    description: '',
  });
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
          category: enumToCategory[listing.category] || 'Guitar',
          condition: listing.condition,
          description: listing.description || '',
        });
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
          category: categoryToEnum[form.category],
          condition: form.condition,
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
        } catch {
          failed.push(file.name);
        }
      }
      setUploadStatus('');
      if (failed.length > 0) {
        window.alert(`Changes saved, but these files failed to upload: ${failed.join(', ')}`);
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
          <label className="field-label" htmlFor="category">Category</label>
          <select className="field field--select" id="category" name="category" value={form.category} onChange={handleChange}>
            {Object.keys(categoryToEnum).map(cat => <option key={cat} value={cat}>{cat}</option>)}
          </select>
        </div>

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
