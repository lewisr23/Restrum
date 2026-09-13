import { useState, useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';

import { API, mediaUrl } from '../lib/config';
import { CategoryIcon } from './Icon';

// The gear adviser: a small chat panel that sits over the browse page and can
// search the actual catalogue.
//
// It renders nothing at all unless the server says the feature is switched
// on, which it only is when an Anthropic API key is configured. A chat button
// that always apologises is worse than no chat button, and a fork of this
// project should not grow a broken feature just by being cloned.

type Message = { role: 'user' | 'assistant'; content: string; listings?: any[] };

const OPENERS = [
  'First electric guitar, about £300?',
  'A turntable that does not need a separate phono stage',
  'What 7" singles are going cheap?',
  'I need a lead for a pedalboard',
];

function ListingChip({ listing }: { listing: any }) {
  const image = listing.media?.find((m: any) => m.media_type === 'IMAGE')?.url ?? null;

  return (
    <Link to={`/listing/${listing.id}`} className="adviser-listing">
      {image
        ? <img className="adviser-listing__image" src={mediaUrl(image)} alt={listing.title} />
        : (
          <span className="adviser-listing__image adviser-listing__image--empty">
            <CategoryIcon path={listing.category?.path} size={18} />
          </span>
        )}
      <span className="adviser-listing__body">
        <span className="adviser-listing__title">{listing.title}</span>
        <span className="adviser-listing__meta">
          £{listing.price} · {listing.condition.toLowerCase()}
        </span>
      </span>
    </Link>
  );
}

function GearAdviser() {
  const [available, setAvailable] = useState(false);
  const [open, setOpen] = useState(false);
  const [messages, setMessages] = useState<Message[]>([]);
  const [draft, setDraft] = useState('');
  const [thinking, setThinking] = useState(false);
  const [error, setError] = useState('');
  const scrollRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    let live = true;

    fetch(`${API}/api/recommendations/status`, { headers: { Accept: 'application/json' } })
      .then(res => (res.ok ? res.json() : { available: false }))
      .then(body => { if (live) setAvailable(Boolean(body.available)); })
      .catch(() => { /* No adviser. Not an error worth showing anyone. */ });

    return () => { live = false; };
  }, []);

  // Keep the newest message in view as the conversation grows.
  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, thinking]);

  const ask = async (question: string) => {
    const text = question.trim();
    if (!text || thinking) return;

    // The user's turn goes up immediately. Waiting for the server to echo it
    // back makes the panel feel broken for the second or two Claude takes.
    const next: Message[] = [...messages, { role: 'user', content: text }];
    setMessages(next);
    setDraft('');
    setError('');
    setThinking(true);

    try {
      const res = await fetch(`${API}/api/recommendations`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          // Only role and content: the listing cards attached to previous
          // answers are for display here and mean nothing to the model.
          messages: next.map(m => ({ role: m.role, content: m.content })),
        }),
      });

      if (res.status === 429) {
        setError('That is a lot of questions in a short time. Give it a minute.');
        return;
      }

      const body = await res.json().catch(() => null);

      if (!res.ok) {
        setError(body?.message || 'The adviser could not answer that one.');
        return;
      }

      setMessages([...next, {
        role: 'assistant',
        content: body.reply,
        listings: body.listings?.data ?? [],
      }]);
    } catch {
      setError('Could not reach the server.');
    } finally {
      setThinking(false);
    }
  };

  if (!available) return null;

  return (
    <>
      <button
        className={`adviser-launch${open ? ' adviser-launch--open' : ''}`}
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        aria-label={open ? 'Close the gear adviser' : 'Ask the gear adviser'}
      >
        <span className="adviser-launch__icon" aria-hidden="true">
          {open ? (
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round">
              <path d="M6 6 18 18M18 6 6 18" />
            </svg>
          ) : (
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
              <path d="M20.5 12a8.5 8.5 0 0 1-12.2 7.7L3.5 21l1.3-4.6A8.5 8.5 0 1 1 20.5 12z" />
            </svg>
          )}
        </span>
        <span className="adviser-launch__label">{open ? 'Close' : 'Ask about gear'}</span>
      </button>

      {open && (
        <section className="adviser" aria-label="Gear adviser">
          <header className="adviser__head">
            <div>
              <h2 className="adviser__title">Gear adviser</h2>
              <p className="adviser__subtitle">Searches what is actually for sale</p>
            </div>
          </header>

          <div className="adviser__scroll" ref={scrollRef}>
            {messages.length === 0 && (
              <div className="adviser__intro">
                <p className="adviser__intro-text">
                  Tell me what you are after, what you play, or what you have to
                  spend. I will look through the listings and point at the ones
                  worth a look, or tell you if there is nothing good on today.
                </p>
                <div className="adviser__openers">
                  {OPENERS.map(opener => (
                    <button key={opener} className="adviser__opener" onClick={() => ask(opener)}>
                      {opener}
                    </button>
                  ))}
                </div>
              </div>
            )}

            {messages.map((message, index) => (
              <div key={index} className={`adviser-msg adviser-msg--${message.role}`}>
                <p className="adviser-msg__text">{message.content}</p>

                {message.listings && message.listings.length > 0 && (
                  <div className="adviser-msg__listings">
                    {message.listings.map((listing: any) => (
                      <ListingChip key={listing.id} listing={listing} />
                    ))}
                  </div>
                )}
              </div>
            ))}

            {thinking && (
              <div className="adviser-msg adviser-msg--assistant">
                <span className="adviser-typing" aria-label="Looking through the listings">
                  <span /><span /><span />
                </span>
              </div>
            )}

            {error && <p className="adviser__error">{error}</p>}
          </div>

          <form
            className="adviser__compose"
            onSubmit={e => { e.preventDefault(); ask(draft); }}
          >
            <input
              className="adviser__input"
              value={draft}
              onChange={e => setDraft(e.target.value)}
              placeholder="What are you looking for?"
              maxLength={2000}
              aria-label="Ask the gear adviser"
            />
            <button className="adviser__send" type="submit" disabled={thinking || !draft.trim()}>
              {thinking ? '...' : 'Ask'}
            </button>
          </form>

          <p className="adviser__footnote">
            AI, so it can be wrong. Check the listing before you buy.
          </p>
        </section>
      )}
    </>
  );
}

export default GearAdviser;
