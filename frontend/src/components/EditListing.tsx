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


// Same upload helper/pattern as CreateListing.tsx's uploadMedia -- one
// multipart request per file, against an already-existing listing ID. Kept
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
          // response wasn't JSON -- stick with the status code
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
      <div style={{ padding: '24px', color: 'white' }}>
        <p>You need to <span style={{ color: '#4caf50', cursor: 'pointer' }} onClick={() => navigate('/login')}>log in</span> to edit a listing.</p>
      </div>
    );
  }

  if (loading) return <div style={{ color: 'white', padding: '24px' }}>Loading...</div>;
  if (notAllowed) return <div style={{ color: 'white', padding: '24px' }}>You can only edit your own listings.</div>;
  if (error) return <div style={{ color: 'white', padding: '24px' }}>{error}</div>;

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

      // Text fields saved -- now upload any newly selected media against
      // the same listing, same best-effort pattern as CreateListing: a
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

  const fileInputStyle = {
    width: '100%',
    padding: '10px',
    marginBottom: '8px',
    background: '#1a1a1a',
    color: 'white',
    border: '1px solid #444',
    borderRadius: '4px',
    boxSizing: 'border-box' as const,
  };

  const mediaTypeIcon: Record<string, string> = { IMAGE: '🖼️', AUDIO: '♪', VIDEO: '▶' };

  return (
    <div style={{ padding: '24px', color: 'white', maxWidth: '500px' }}>
      <button
        onClick={() => navigate(`/listing/${id}`)}
        style={{ background: 'none', border: '1px solid #444', color: 'white', padding: '8px 16px', borderRadius: '4px', cursor: 'pointer', marginBottom: '24px' }}
      >
        ← Back
      </button>
      <h1 style={{ marginBottom: '24px' }}>Edit Listing</h1>

      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '0' }}>
        <label style={{ display: 'block', marginBottom: '4px', color: '#aaa' }}>Title *</label>
        <input name="title" value={form.title} onChange={handleChange} required
          style={{ width: '100%', padding: '10px', marginBottom: '16px', background: '#1a1a1a', color: 'white', border: '1px solid #444', borderRadius: '4px', boxSizing: 'border-box' }} />

        <label style={{ display: 'block', marginBottom: '4px', color: '#aaa' }}>Price (£) *</label>
        <input name="price" value={form.price} onChange={handleChange} type="number" required
          style={{ width: '100%', padding: '10px', marginBottom: '16px', background: '#1a1a1a', color: 'white', border: '1px solid #444', borderRadius: '4px', boxSizing: 'border-box' }} />

        <label style={{ display: 'block', marginBottom: '4px', color: '#aaa' }}>Location *</label>
        <input name="location" value={form.location} onChange={handleChange} required
          style={{ width: '100%', padding: '10px', marginBottom: '16px', background: '#1a1a1a', color: 'white', border: '1px solid #444', borderRadius: '4px', boxSizing: 'border-box' }} />

        <label style={{ display: 'block', marginBottom: '4px', color: '#aaa' }}>Category</label>
        <select name="category" value={form.category} onChange={handleChange}
          style={{ width: '100%', padding: '10px', marginBottom: '16px', background: '#1a1a1a', color: 'white', border: '1px solid #444', borderRadius: '4px' }}>
          {Object.keys(categoryToEnum).map(cat => <option key={cat} value={cat}>{cat}</option>)}
        </select>

        <label style={{ display: 'block', marginBottom: '4px', color: '#aaa' }}>Condition</label>
        <select name="condition" value={form.condition} onChange={handleChange}
          style={{ width: '100%', padding: '10px', marginBottom: '16px', background: '#1a1a1a', color: 'white', border: '1px solid #444', borderRadius: '4px' }}>
          {conditionOptions.map(c => <option key={c} value={c}>{c.charAt(0) + c.slice(1).toLowerCase()}</option>)}
        </select>

        <label style={{ display: 'block', marginBottom: '4px', color: '#aaa' }}>Description</label>
        <textarea name="description" value={form.description} onChange={handleChange}
          rows={4}
          style={{ width: '100%', padding: '10px', marginBottom: '8px', background: '#1a1a1a', color: 'white', border: '1px solid #444', borderRadius: '4px', resize: 'vertical', boxSizing: 'border-box' }} />
        <div style={{ borderTop: '1px solid #333', paddingTop: '20px', marginTop: '8px' }}>
          <h3 style={{ margin: '0 0 4px', fontSize: '16px' }}>Media</h3>
          <p style={{ margin: '0 0 16px', color: '#888', fontSize: '13px' }}>
            Remove existing photos, audio, or video, or add more below.
          </p>

          {existingMedia.length === 0 && (
            <p style={{ color: '#666', fontSize: '13px', marginBottom: '16px' }}>No media on this listing yet.</p>
          )}
          {existingMedia.length > 0 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', marginBottom: '20px' }}>
              {existingMedia.map(m => (
                <div key={m.id} style={{ display: 'flex', alignItems: 'center', gap: '10px', padding: '8px 10px', background: '#1a1a1a', border: '1px solid #333', borderRadius: '4px' }}>
                  {m.media_type === 'IMAGE' ? (
                    <img src={mediaUrl(m.url)} alt="" style={{ width: '36px', height: '36px', objectFit: 'cover', borderRadius: '4px', flexShrink: 0 }} />
                  ) : (
                    <span style={{ width: '36px', height: '36px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: '#222', borderRadius: '4px', fontSize: '16px', flexShrink: 0 }}>
                      {mediaTypeIcon[m.media_type]}
                    </span>
                  )}
                  <span style={{ flex: 1, fontSize: '13px', color: '#ccc' }}>{m.media_type.charAt(0) + m.media_type.slice(1).toLowerCase()}</span>
                  <button
                    type="button"
                    onClick={() => handleRemoveMedia(m.id)}
                    disabled={removingId === m.id}
                    style={{ padding: '5px 12px', background: 'none', color: '#f44', border: '1px solid #f44', borderRadius: '4px', cursor: 'pointer', fontSize: '12px' }}
                  >
                    {removingId === m.id ? '...' : 'Remove'}
                  </button>
                </div>
              ))}
            </div>
          )}

          <label style={{ display: 'block', marginBottom: '4px', color: '#aaa' }}>Add photos</label>
          <input type="file" accept="image/*" multiple
            onChange={e => setNewImages(e.target.files ? Array.from(e.target.files) : [])}
            style={fileInputStyle} />
          <p style={{ margin: '0 0 16px', color: '#666', fontSize: '12px' }}>
            {newImages.length > 0 ? `${newImages.length} photo(s) selected` : 'Optional.'}
          </p>

          <label style={{ display: 'block', marginBottom: '4px', color: '#aaa' }}>Add audio demo</label>
          <input type="file" accept="audio/*" multiple
            onChange={e => setNewAudioFiles(e.target.files ? Array.from(e.target.files) : [])}
            style={fileInputStyle} />
          <p style={{ margin: '0 0 16px', color: '#666', fontSize: '12px' }}>
            {newAudioFiles.length > 0 ? `${newAudioFiles.length} audio file(s) selected` : 'Optional.'}
          </p>

          <label style={{ display: 'block', marginBottom: '4px', color: '#aaa' }}>Add video demo</label>
          <input type="file" accept="video/*" multiple
            onChange={e => setNewVideoFiles(e.target.files ? Array.from(e.target.files) : [])}
            style={fileInputStyle} />
          <p style={{ margin: '0 0 24px', color: '#666', fontSize: '12px' }}>
            {newVideoFiles.length > 0 ? `${newVideoFiles.length} video file(s) selected` : 'Optional.'}
          </p>
        </div>

        {error && <p style={{ color: '#f44', margin: '0 0 16px' }}>{error}</p>}
        {uploadStatus && <p style={{ color: '#4caf50', margin: '0 0 16px', fontSize: '13px' }}>{uploadStatus}</p>}

        <button type="submit" disabled={saving}
          style={{ width: '100%', padding: '12px', background: '#4caf50', color: 'white', border: 'none', borderRadius: '4px', cursor: 'pointer', fontSize: '16px' }}>
          {saving ? 'Saving...' : 'Save Changes'}
        </button>
      </form>
    </div>
  );
}

export default EditListing;
