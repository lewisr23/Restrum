import React, { useState } from 'react';
import { API } from '../lib/config';
import { useAuth } from '../context/AuthContext';

const REASONS: { value: string; label: string }[] = [
  { value: 'SCAM', label: 'It looks like a scam' },
  { value: 'STOLEN', label: 'I think this is stolen' },
  { value: 'COUNTERFEIT', label: 'Fake or misrepresented as genuine' },
  { value: 'OFF_PLATFORM_PAYMENT', label: 'They asked me to pay outside Restrum' },
  { value: 'PROHIBITED', label: 'Should not be for sale here' },
  { value: 'ABUSE', label: 'Abusive behaviour' },
  { value: 'OTHER', label: 'Something else' },
];

/**
 * Report a listing or a user.
 *
 * Off-platform payment is listed first among the specifics on purpose: it
 * is the most common way people get done over here, because leaving escrow
 * is the moment every protection this site offers stops applying, and a
 * buyer being asked for a bank transfer often does not know that is a red
 * flag until someone names it.
 */
function ReportButton({ listingId, userId, label = 'Report' }: {
  listingId?: number;
  userId?: number;
  label?: string;
}) {
  const { user } = useAuth();
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('SCAM');
  const [detail, setDetail] = useState('');
  const [sending, setSending] = useState(false);
  const [done, setDone] = useState('');
  const [error, setError] = useState('');

  // Reporting needs an account, so there is someone to come back to if the
  // report turns out to be the malicious half of the story.
  if (!user) return null;

  const endpoint = listingId
    ? `${API}/api/listings/${listingId}/report`
    : `${API}/api/users/${userId}/report`;

  const handleSubmit = async () => {
    setSending(true);
    setError('');

    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${user.token}`,
        },
        body: JSON.stringify({ reason, detail: detail || null }),
      });

      const body = await res.json().catch(() => null);

      if (res.ok) {
        setDone(body?.message || 'Thanks. We will look at this.');
      } else {
        setError(body?.errors?.subject?.[0] || body?.message || 'That did not work.');
      }
    } catch {
      setError('Could not reach the server.');
    } finally {
      setSending(false);
    }
  };

  if (done) {
    return <p className="report__done">{done}</p>;
  }

  if (!open) {
    return (
      <button className="report__trigger" onClick={() => setOpen(true)}>
        {label}
      </button>
    );
  }

  return (
    <div className="report">
      <h3 className="report__title">What is wrong here?</h3>

      <div className="field-group">
        <select className="field" value={reason} onChange={e => setReason(e.target.value)}>
          {REASONS.map(r => (
            <option key={r.value} value={r.value}>{r.label}</option>
          ))}
        </select>
      </div>

      <div className="field-group">
        <textarea
          className="field"
          rows={3}
          maxLength={2000}
          value={detail}
          onChange={e => setDetail(e.target.value)}
          placeholder="Anything that would help us look into it (optional)"
        />
      </div>

      {error && <p className="field-error">{error}</p>}

      <div className="report__actions">
        <button className="btn-danger btn-sm" onClick={handleSubmit} disabled={sending}>
          {sending ? 'Sending...' : 'Send report'}
        </button>
        <button className="btn-ghost btn-sm" onClick={() => setOpen(false)}>
          Cancel
        </button>
      </div>
    </div>
  );
}

export default ReportButton;
