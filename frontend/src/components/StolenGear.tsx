import { useState, useEffect, useCallback, FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

import { API } from '../lib/config';

// The stolen gear register.
//
// Checking a serial needs no account, on purpose: the person who most needs
// it is about to buy a guitar somewhere else entirely. Reporting needs one,
// because a report can put someone's listing on hold and that should never
// be anonymous.

type Match = {
  brand: string | null;
  description: string;
  stolen_on: string | null;
  location: string | null;
  reported_on: string;
  police_reported: boolean;
};

type CheckResult = { serial: string; reported: boolean; reports: Match[] };

type MyReport = {
  id: number;
  serial_number: string;
  brand: string | null;
  description: string;
  stolen_on: string | null;
  location: string | null;
  police_reference: string | null;
  status: 'ACTIVE' | 'RECOVERED';
  reported_on: string;
};

const BLANK_REPORT = { serial_number: '', brand: '', description: '', stolen_on: '', location: '', police_reference: '' };

function formatDate(iso: string | null): string {
  return iso ? new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
}

function StolenGear() {
  const { user } = useAuth();

  const [serial, setSerial] = useState('');
  const [checking, setChecking] = useState(false);
  const [result, setResult] = useState<CheckResult | null>(null);
  const [checkProblem, setCheckProblem] = useState('');

  const [report, setReport] = useState(BLANK_REPORT);
  const [submitting, setSubmitting] = useState(false);
  const [reportProblem, setReportProblem] = useState('');
  const [reported, setReported] = useState(false);
  const [mine, setMine] = useState<MyReport[]>([]);

  const loadMine = useCallback(() => {
    if (!user) return;
    fetch(`${API}/api/stolen`, { headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` } })
      .then(res => (res.ok ? res.json() : null))
      .then(body => { if (body) setMine(body.data); })
      .catch(() => {});
  }, [user]);

  useEffect(() => { loadMine(); }, [loadMine]);

  const check = async (e: FormEvent) => {
    e.preventDefault();
    setChecking(true);
    setCheckProblem('');
    setResult(null);
    try {
      const res = await fetch(`${API}/api/stolen/check?serial=${encodeURIComponent(serial.trim())}`, {
        headers: { Accept: 'application/json' },
      });
      const body = await res.json().catch(() => null);
      if (res.ok) {
        setResult(body);
      } else if (res.status === 429) {
        setCheckProblem('Too many checks in a short time. Try again in a minute.');
      } else {
        setCheckProblem(body?.message || 'Could not check that serial.');
      }
    } catch {
      setCheckProblem('Could not reach the server.');
    } finally {
      setChecking(false);
    }
  };

  const submitReport = async (e: FormEvent) => {
    e.preventDefault();
    if (!user) return;
    setSubmitting(true);
    setReportProblem('');
    try {
      const res = await fetch(`${API}/api/stolen`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${user.token}` },
        // Blank optional fields go as null rather than empty strings, so the
        // register stores "not given" rather than "given as nothing".
        body: JSON.stringify(Object.fromEntries(
          Object.entries(report).map(([key, value]) => [key, value.trim() === '' ? null : value.trim()]),
        )),
      });
      const body = await res.json().catch(() => null);
      if (res.ok) {
        setReport(BLANK_REPORT);
        setReported(true);
        loadMine();
      } else if (body?.reason === 'email_unverified') {
        setReportProblem('Confirm your email address first, then report it.');
      } else {
        const first = body?.errors ? Object.values(body.errors).flat()[0] : null;
        setReportProblem((first as string) || body?.message || 'Could not save the report.');
      }
    } catch {
      setReportProblem('Could not reach the server.');
    } finally {
      setSubmitting(false);
    }
  };

  const markRecovered = async (id: number) => {
    if (!user || !window.confirm('Mark this as recovered? It will stop matching listings.')) return;
    await fetch(`${API}/api/stolen/${id}/recovered`, {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` },
    }).catch(() => {});
    loadMine();
  };

  const field = (name: keyof typeof BLANK_REPORT) => ({
    id: `stolen-${name}`,
    className: 'field',
    value: report[name],
    onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setReport(r => ({ ...r, [name]: e.target.value })),
  });

  return (
    <div className="stolen">
      <h1 className="stolen__title">Stolen gear register</h1>
      <p className="stolen__lede">
        Instruments get taken from cars, vans and rehearsal rooms, and turn up
        for sale a few weeks later. A serial number is what connects the two.
        Check one before you buy, here or anywhere else, and report yours if
        it has gone. Every serial listed on Restrum is checked against this
        register, and a match puts the listing on hold.
      </p>

      <section className="panel stolen__section">
        <h2 className="stolen__heading">Check a serial number</h2>
        <form className="stolen__check" onSubmit={check}>
          <input
            className="field"
            aria-label="Serial number"
            value={serial}
            onChange={e => setSerial(e.target.value)}
            placeholder="e.g. MX21012345"
            maxLength={100}
            required
          />
          <button className="btn-primary" type="submit" disabled={checking || serial.trim() === ''}>
            {checking ? 'Checking...' : 'Check'}
          </button>
        </form>

        {checkProblem && <p className="field-error">{checkProblem}</p>}

        {result && !result.reported && (
          <div className="notice notice--muted">
            <strong>{result.serial} is not on the register.</strong> That only
            means nobody has reported it stolen here. It is a good sign, not a
            guarantee.
          </div>
        )}

        {result && result.reported && (
          <div className="notice notice--error">
            <strong>{result.serial} has been reported stolen.</strong>
            {result.reports.map((match, i) => (
              <p key={i} className="stolen__match">
                {match.brand && <>{match.brand}: </>}{match.description}
                {match.location && <>, {match.location}</>}
                {match.stolen_on && <>, taken {formatDate(match.stolen_on)}</>}.
                {' '}Reported {formatDate(match.reported_on)}
                {match.police_reported ? ', with a police crime reference.' : '.'}
              </p>
            ))}
            <p className="stolen__match">
              Do not buy it, and do not confront the seller. Call the police on
              101 and give them the serial and where you saw it for sale. If it
              is listed on Restrum, report the listing as well.
            </p>
          </div>
        )}
      </section>

      <section className="panel stolen__section">
        <h2 className="stolen__heading">Report your gear stolen</h2>

        {!user ? (
          <p className="stolen__muted">
            <Link to="/login">Log in</Link> or <Link to="/register">create an account</Link> to
            report something stolen. It is free, and we need to be able to tell you if it turns up.
          </p>
        ) : (
          <>
            <p className="stolen__muted">
              If a listing on Restrum has the same serial, it goes on hold,
              our team is told, and so are you. Your name and contact details
              are never shown to anyone checking the register.
            </p>

            {reported && (
              <div className="notice notice--muted">
                Reported. We checked the live listings straight away and will
                check every new one against it too.
              </div>
            )}

            <form className="form" onSubmit={submitReport}>
              <div className="field-group">
                <label className="field-label" htmlFor="stolen-serial_number">Serial number *</label>
                <input {...field('serial_number')} maxLength={100} required />
              </div>
              <div className="field-group">
                <label className="field-label" htmlFor="stolen-brand">Brand</label>
                <input {...field('brand')} maxLength={100} placeholder="e.g. Fender" />
              </div>
              <div className="field-group">
                <label className="field-label" htmlFor="stolen-description">What it is *</label>
                <input {...field('description')} maxLength={500} required placeholder="e.g. Sunburst Stratocaster, mint pickguard, sticker on the case" />
              </div>
              <div className="field-group">
                <label className="field-label" htmlFor="stolen-stolen_on">When it was taken</label>
                <input {...field('stolen_on')} type="date" max={new Date().toISOString().slice(0, 10)} />
              </div>
              <div className="field-group">
                <label className="field-label" htmlFor="stolen-location">Where</label>
                <input {...field('location')} maxLength={120} placeholder="Town or area, e.g. Leeds" />
              </div>
              <div className="field-group">
                <label className="field-label" htmlFor="stolen-police_reference">Police crime reference</label>
                <input {...field('police_reference')} maxLength={60} />
                <p className="field-hint">
                  Kept private. It tells our team the theft was reported, and it
                  is what the police will ask for if it turns up.
                </p>
              </div>

              {reportProblem && <p className="field-error">{reportProblem}</p>}

              <button className="btn-primary" type="submit" disabled={submitting}>
                {submitting ? 'Reporting...' : 'Report stolen'}
              </button>
            </form>
          </>
        )}
      </section>

      {mine.length > 0 && (
        <section className="stolen__section">
          <h2 className="stolen__heading">Your reports</h2>
          <ul className="stolen__mine">
            {mine.map(item => (
              <li key={item.id} className="panel stolen__item">
                <div>
                  <p className="stolen__item-title">
                    {item.brand && <>{item.brand}: </>}{item.description}
                  </p>
                  <p className="stolen__muted">
                    Serial {item.serial_number}, reported {formatDate(item.reported_on)}
                    {item.status === 'RECOVERED' && ', recovered'}
                  </p>
                </div>
                {item.status === 'ACTIVE' && (
                  <button className="btn-ghost" onClick={() => markRecovered(item.id)}>Got it back</button>
                )}
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}

export default StolenGear;
