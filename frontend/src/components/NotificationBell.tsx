import { useState, useEffect, useRef, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { BellIcon } from './Icon';

import { API } from '../lib/config';

interface Note {
  id: string;
  kind: string;
  title: string;
  body: string;
  path: string;
  read: boolean;
  created_at: string;
}

/**
 * How often the bell asks whether anything has happened.
 *
 * Polled rather than pushed. There is a websocket on this site already, for
 * chat, and it would carry these perfectly well, but a notification is not
 * time critical in the way a message in an open conversation is: nobody is
 * sitting watching for "you sold something" the way they watch for a reply.
 * A minute's delay costs nothing, and a second private channel per user is a
 * real thing to run and keep running.
 */
const POLL_MS = 60_000;

/** "3 minutes ago" beats a timestamp for something that just happened. */
function ago(iso: string): string {
  const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);

  if (seconds < 60) return 'just now';
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86_400) return `${Math.floor(seconds / 3600)}h ago`;
  if (seconds < 604_800) return `${Math.floor(seconds / 86_400)}d ago`;

  return new Date(iso).toLocaleDateString([], { day: 'numeric', month: 'short' });
}

function NotificationBell() {
  const { user } = useAuth();
  const navigate = useNavigate();

  const [notes, setNotes] = useState<Note[]>([]);
  const [unread, setUnread] = useState(0);
  const [open, setOpen] = useState(false);
  const panelRef = useRef<HTMLDivElement>(null);

  const token = user?.token;

  const load = useCallback(async () => {
    if (!token) return;

    try {
      const res = await fetch(`${API}/api/notifications`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });

      if (!res.ok) return;

      const body = await res.json();
      setNotes(body.data);
      setUnread(body.unread_count);
    } catch {
      // A failed poll is not worth telling anyone about. The next one is a
      // minute away, and an error state on a bell is noisier than the thing
      // it is reporting.
    }
  }, [token]);

  useEffect(() => {
    if (!token) {
      setNotes([]);
      setUnread(0);

      return;
    }

    load();
    const timer = window.setInterval(load, POLL_MS);

    return () => window.clearInterval(timer);
  }, [token, load]);

  // Same click-outside handling as the account menu next to it.
  useEffect(() => {
    if (!open) return;

    const onClickOutside = (event: MouseEvent) => {
      if (panelRef.current && !panelRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    };

    document.addEventListener('mousedown', onClickOutside);

    return () => document.removeEventListener('mousedown', onClickOutside);
  }, [open]);

  const markRead = async (id?: string) => {
    if (!token) return;

    // Cleared here before the request comes back, because the count is the
    // whole point of the badge and waiting a round trip to drop it makes
    // the button feel broken. A failed request simply means the next poll
    // puts it back, which is the right way round for this to be wrong.
    setNotes(prev => prev.map(n => (id === undefined || n.id === id ? { ...n, read: true } : n)));
    setUnread(prev => (id === undefined ? 0 : Math.max(0, prev - 1)));

    try {
      await fetch(`${API}/api/notifications/read`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify(id === undefined ? {} : { id }),
      });
    } catch {
      // See above: the next poll corrects it.
    }
  };

  const openNote = (note: Note) => {
    setOpen(false);

    if (!note.read) markRead(note.id);

    navigate(note.path);
  };

  if (!user) return null;

  return (
    <div className="notification-bell" ref={panelRef}>
      <button
        type="button"
        className="notification-bell__trigger"
        onClick={() => setOpen(!open)}
        aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
        aria-expanded={open}
      >
        <BellIcon size={17} />
        {unread > 0 && (
          // Capped, because the number stops being information somewhere
          // around ten and starts being a wide badge.
          <span className="notification-bell__count">{unread > 9 ? '9+' : unread}</span>
        )}
      </button>

      {open && (
        <div className="notification-panel">
          <div className="notification-panel__head">
            <p className="notification-panel__title">Notifications</p>
            {unread > 0 && (
              <button type="button" className="notification-panel__clear" onClick={() => markRead()}>
                Mark all read
              </button>
            )}
          </div>

          {notes.length === 0 ? (
            <p className="notification-panel__empty">
              Nothing yet. Sales, offers and deliveries land here.
            </p>
          ) : (
            <ul className="notification-panel__list">
              {notes.map(note => (
                <li key={note.id}>
                  <button
                    type="button"
                    className={`notification-item${note.read ? '' : ' notification-item--unread'}`}
                    onClick={() => openNote(note)}
                  >
                    <span className="notification-item__title">{note.title}</span>
                    <span className="notification-item__body">{note.body}</span>
                    <span className="notification-item__time">{ago(note.created_at)}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}

export default NotificationBell;
