import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

import { API } from '../lib/config';

const categoryToEnum: Record<string, string> = {
  'Guitar': 'GUITAR',
  'Drums': 'DRUMS',
  'Microphone': 'MICROPHONE',
  'Synths': 'SYNTHS',
  'Audio Equipment': 'AUDIO_EQUIPMENT',
};

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
    category: 'Guitar',
    condition: 'GOOD',
    description: '',
  });
  const [images, setImages] = useState<File[]>([]);
  const [audioFiles, setAudioFiles] = useState<File[]>([]);
  const [videoFiles, setVideoFiles] = useState<File[]>([]);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [uploadStatus, setUploadStatus] = useState('');

  if (!user) {
    return (
      <div style={{ padding: '24px', color: 'white' }}>
        <p>You need to <span style={{ color: '#4caf50', cursor: 'pointer' }} onClick={() => navigate('/login')}>log in</span> to create a listing.</p>
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
          category: categoryToEnum[form.category],
          condition: form.condition,
        }),
      });
      if (!res.ok) {
        setError('Failed to create listing.');
        setLoading(false);
        return;
      }
      // Wrapped in {data: {...listing}} by Laravel's JsonResource.
      const { data } = await res.json();

      // Listing exists now — upload any media against it. Best-effort: if a
      // file fails (too big, wrong type, network blip) we still take the
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
        // Still navigate — the listing was created successfully, media is
        // secondary. Just let them know before we leave.
        window.alert(`Listing posted, but these files failed to upload: ${failed.join(', ')}`);
      }

      navigate(`/listing/${data.id}`);
    } catch {
      setError('Could not connect to server.');
    }
    setLoading(false);
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

  return (
    <div style={{ padding: '24px', color: 'white', maxWidth: '500px' }}>
      <button
        onClick={() => navigate('/')}
        style={{ background: 'none', border: '1px solid #444', color: 'white', padding: '8px 16px', borderRadius: '4px', cursor: 'pointer', marginBottom: '24px' }}
      >
        ← Back
      </button>
      <h1 style={{ marginBottom: '24px' }}>Create a Listing</h1>

      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '0' }}>
        <label style={{ display: 'block', marginBottom: '4px', color: '#aaa' }}>Title *</label>
        <input name="title" value={form.title} onChange={handleChange} placeholder="e.g. Fender Stratocaster" required
          style={{ width: '100%', padding: '10px', marginBottom: '16px', background: '#1a1a1a', color: 'white', border: '1px solid #444', borderRadius: '4px', boxSizing: 'border-box' }} />

        <label style={{ display: 'block', marginBottom: '4px', color: '#aaa' }}>Price (£) *</label>
        <input name="price" value={form.price} onChange={handleChange} placeholder="e.g. 450" type="number" required
          style={{ width: '100%', padding: '10px', marginBottom: '16px', background: '#1a1a1a', color: 'white', border: '1px solid #444', borderRadius: '4px', boxSizing: 'border-box' }} />

        <label style={{ display: 'block', marginBottom: '4px', color: '#aaa' }}>Location *</label>
        <input name="location" value={form.location} onChange={handleChange} placeholder="e.g. Newcastle" required
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
        <textarea name="description" value={form.description} onChange={handleChange} placeholder="Describe the item, condition, what's included..."
          rows={4}
          style={{ width: '100%', padding: '10px', marginBottom: '24px', background: '#1a1a1a', color: 'white', border: '1px solid #444', borderRadius: '4px', resize: 'vertical', boxSizing: 'border-box' }} />

        <label style={{ display: 'block', marginBottom: '4px', color: '#aaa' }}>Photos</label>
        <input type="file" accept="image/*" multiple
          onChange={e => setImages(e.target.files ? Array.from(e.target.files) : [])}
          style={fileInputStyle} />
        <p style={{ margin: '0 0 16px', color: '#666', fontSize: '12px' }}>
          {images.length > 0 ? `${images.length} photo(s) selected` : 'Optional, but listings with photos get more interest.'}
        </p>

        <label style={{ display: 'block', marginBottom: '4px', color: '#aaa' }}>Audio demo</label>
        <input type="file" accept="audio/*" multiple
          onChange={e => setAudioFiles(e.target.files ? Array.from(e.target.files) : [])}
          style={fileInputStyle} />
        <p style={{ margin: '0 0 16px', color: '#666', fontSize: '12px' }}>
          {audioFiles.length > 0 ? `${audioFiles.length} audio file(s) selected` : 'A short clip proving it actually sounds good.'}
        </p>

        <label style={{ display: 'block', marginBottom: '4px', color: '#aaa' }}>Video demo</label>
        <input type="file" accept="video/*" multiple
          onChange={e => setVideoFiles(e.target.files ? Array.from(e.target.files) : [])}
          style={fileInputStyle} />
        <p style={{ margin: '0 0 24px', color: '#666', fontSize: '12px' }}>
          {videoFiles.length > 0 ? `${videoFiles.length} video file(s) selected` : 'Show it being played, and show any cosmetic damage up close.'}
        </p>

        {error && <p style={{ color: '#f44', margin: '0 0 16px' }}>{error}</p>}
        {uploadStatus && <p style={{ color: '#4caf50', margin: '0 0 16px', fontSize: '13px' }}>{uploadStatus}</p>}

        <button type="submit" disabled={loading}
          style={{ width: '100%', padding: '12px', background: '#4caf50', color: 'white', border: 'none', borderRadius: '4px', cursor: 'pointer', fontSize: '16px' }}>
          {loading ? 'Posting...' : 'Post Listing'}
        </button>
      </form>
    </div>
  );
}

export default CreateListing;
