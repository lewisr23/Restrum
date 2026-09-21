import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { API } from '../lib/config';
import { useAuth } from '../context/AuthContext';

interface ReportRow {
  id: number;
  reason: string;
  detail: string | null;
  status: string;
  created_at: string;
  reporter: { id: number; username: string } | null;
  subject: { id: number; username: string; suspended_at: string | null } | null;
  listing: { id: number; title: string; status: string } | null;
}

const REASON_LABELS: Record<string, string> = {
  SCAM: 'Scam',
  STOLEN: 'Stolen',
  COUNTERFEIT: 'Counterfeit',
  OFF_PLATFORM_PAYMENT: 'Off-platform payment',
  PROHIBITED: 'Prohibited item',
  ABUSE: 'Abuse',
  OTHER: 'Other',
};

/**
 * The moderation queue.
 *
 * Deliberately a list and four buttons rather than a dashboard. The useful
 * property of this page is that it exists at all: before it, the only way
 * to act on a scammer was to edit the database by hand.
 *
 * The backend answers 404 rather than 403 to non-admins, so a normal user
 * who guesses the URL sees a not-found rather than confirmation that there
 * is something here worth finding.
 */
function AdminReports() {
  const { user } = useAuth();
  const [reports, setReports] = useState<ReportRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [denied, setDenied] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);

  const authHeaders = useCallback(() => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    Authorization: `Bearer ${user?.token}`,
  }), [user]);

  const load = useCallback(async () => {
    if (!user) return;
    try {
      const res = await fetch(`${API}/api/admin/reports`, { headers: authHeaders() });
      if (res.status === 404) {
        setDenied(true);
        return;
      }
      const body = await res.json();
      setReports(body.data ?? []);
    } catch {
      setDenied(true);
    } finally {
      setLoading(false);
    }
  }, [user, authHeaders]);

  useEffect(() => { load(); }, [load]);

  const act = async (id: number, url: string, payload: object = {}) => {
    setBusyId(id);
    try {
      await fetch(`${API}${url}`, {
        method: 'POST',
        headers: authHeaders(),
        body: JSON.stringify(payload),
      });
      await load();
    } finally {
      setBusyId(null);
    }
  };

  if (!user) return <div className="page text-muted">Log in first.</div>;
  if (loading) return <div className="page text-muted">Loading...</div>;
  if (denied) return <div className="page text-error">Not found.</div>;

  return (
    <div className="page">
      <h1 className="page__title">Open reports</h1>

      {reports.length === 0 ? (
        <p className="text-muted">Nothing waiting. </p>
      ) : (
        <ul className="review-list">
          {reports.map(report => (
            <li key={report.id} className="review">
              <div className="review__head">
                <strong>{REASON_LABELS[report.reason] ?? report.reason}</strong>
                <span className="text-muted">
                  reported by {report.reporter?.username ?? 'Restrum (flagged automatically)'}
                  {' on '}
                  {new Date(report.created_at).toLocaleDateString('en-GB')}
                </span>
              </div>

              {report.listing && (
                <p className="review__comment">
                  Listing:{' '}
                  <Link to={`/listing/${report.listing.id}`}>{report.listing.title}</Link>
                  {' '}({report.listing.status})
                </p>
              )}

              {report.subject && (
                <p className="review__comment">
                  Seller:{' '}
                  <Link to={`/seller/${report.subject.id}`}>{report.subject.username}</Link>
                  {report.subject.suspended_at ? ' (already suspended)' : ''}
                </p>
              )}

              {report.detail && <p className="review__comment">"{report.detail}"</p>}

              <div className="report__actions">
                {report.listing && (
                  <button
                    className="btn-ghost btn-sm"
                    disabled={busyId === report.id}
                    onClick={() => act(report.id, `/api/admin/listings/${report.listing!.id}/remove`)}
                  >
                    Remove listing
                  </button>
                )}
                {report.subject && !report.subject.suspended_at && (
                  <button
                    className="btn-danger btn-sm"
                    disabled={busyId === report.id}
                    onClick={() => {
                      const reason = window.prompt('Reason for suspension (they will not see this):');
                      if (reason) {
                        act(report.id, `/api/admin/users/${report.subject!.id}/suspend`, { reason });
                      }
                    }}
                  >
                    Suspend seller
                  </button>
                )}
                <button
                  className="btn-ghost btn-sm"
                  disabled={busyId === report.id}
                  onClick={() => act(report.id, `/api/admin/reports/${report.id}/resolve`, { status: 'ACTIONED' })}
                >
                  Mark actioned
                </button>
                <button
                  className="btn-ghost btn-sm"
                  disabled={busyId === report.id}
                  onClick={() => act(report.id, `/api/admin/reports/${report.id}/resolve`, { status: 'DISMISSED' })}
                >
                  Dismiss
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export default AdminReports;
