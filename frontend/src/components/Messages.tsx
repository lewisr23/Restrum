import { useState, useEffect, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { createEcho } from '../lib/socket';

import { API } from '../lib/config';

interface ChatMessage {
  id: number;
  conversation_id: number;
  sender_id: number;
  content: string;
  message_type: 'TEXT' | 'PRICE_OFFER';
  offer_amount: number | null;
  offer_status: 'PENDING' | 'ACCEPTED' | 'DECLINED' | null;

  // When an accepted offer stops being claimable. Accepting agrees a price
  // rather than making a sale, so this is the deadline the buyer has to
  // actually pay by, and after it the listing simply costs what it says.
  offer_expires_at: string | null;
  created_at: string;

  // Null for almost every message. When set, it names what the off-platform
  // scanner spotted, and the bubble carries a warning underneath.
  safety_flags: string[] | null;
}

interface ConversationSummary {
  id: number;
  listing: { id: number; title: string; price: number; status: string };
  other_participant: { id: number; username: string; location: string | null; community_verified: boolean };
  am_i_seller: boolean;
  last_message_at: string;
  latest_message_preview: string | null;
  has_endorsed_other: boolean;
  unread_count: number;
}

// Which scanner signals get the stronger wording. Mirrors the SEVERE list
// in app/Services/Safety/OffPlatformScanner.php. Duplicated rather than
// sent down with each message because it is a presentation decision, and a
// wrong entry here softens a warning rather than breaking anything.
const SEVERE_FLAGS = [
  'bank_details',
  'bank_account_digits',
  'iban_digits',
  'bank_transfer',
  'friends_and_family',
  'untraceable_rail',
  'dodging_fees',
];

/**
 * The warning under a message that looked like it was steering the sale off
 * Restrum.
 *
 * Shown to both people, not just the recipient. The one being targeted needs
 * it most, but a seller who sees their own message carrying a notice learns
 * where the line is, and an honest one who just offered their phone number
 * for a collection finds out why that looked odd.
 *
 * The message itself is never hidden. See OffPlatformScanner for the
 * reasoning: a block teaches evasion, and plenty of these are innocent.
 */
function SafetyWarning({ flags, mine }: { flags: string[]; mine: boolean }) {
  const severe = flags.some(f => SEVERE_FLAGS.includes(f));

  return (
    <div className={`bubble__warning${severe ? ' bubble__warning--severe' : ''}`}>
      {mine ? (
        <p>
          {severe
            ? 'This reads like an offer to settle up away from Restrum. Payments made outside the site are not covered by anything here, and asking for one can get an account suspended.'
            : 'Heads up: swapping contact details or naming another payment app makes a buyer wary, because it is how most scams on marketplaces start.'}
        </p>
      ) : (
        <p>
          {severe
            ? 'Careful. This message is pointing you away from paying through Restrum. If you pay this way your money is gone the moment you send it: there is no escrow, no refund and nobody to appeal to. Report it if it feels wrong.'
            : 'Just so you know: paying anywhere other than through Restrum leaves you with no buyer protection, no escrow and no refund.'}
        </p>
      )}
    </div>
  );
}

/** "Tomorrow at 14:00" is harder to misread than a bare timestamp. */
function deadlineLabel(iso: string): string {
  const when = new Date(iso);
  const today = new Date();
  const sameDay = when.toDateString() === today.toDateString();
  const time = when.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

  return sameDay
    ? `today at ${time}`
    : `${when.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' })} at ${time}`;
}

/**
 * What an accepted offer looks like to each side of it.
 *
 * It used to say "Accepted" and nothing else, which was accurate when
 * accepting sold the item on the spot. Now it agrees a price and the buyer
 * still has to pay, so the bubble has to say three things the old one did
 * not: that the sale has not happened, what the deadline is, and, for the
 * seller, that their listing is still on sale in the meantime. A seller who
 * thinks they have sold something and has not is how a marketplace loses a
 * seller.
 */
function AcceptedOffer({
  message,
  amIBuyer,
  listing,
  onPay,
}: {
  message: ChatMessage;
  amIBuyer: boolean;
  listing: ConversationSummary['listing'] | undefined;
  onPay: () => void;
}) {
  const expired = message.offer_expires_at !== null && new Date(message.offer_expires_at) <= new Date();
  const goneToSomeoneElse = listing?.status === 'SOLD';

  if (goneToSomeoneElse) {
    return (
      <p className="bubble__status bubble__status--declined">
        {amIBuyer
          ? 'Accepted, but this has now sold. An agreed price does not hold the item: whoever pays first gets it.'
          : 'Accepted, and this listing has since sold.'}
      </p>
    );
  }

  if (expired) {
    return (
      <p className="bubble__status bubble__status--declined">
        {amIBuyer
          ? 'Accepted, but the deadline to pay has passed. Make another offer if you are still interested.'
          : 'Accepted, but they did not pay in time. The listing is back at its full price.'}
      </p>
    );
  }

  if (!amIBuyer) {
    return (
      <p className="bubble__status bubble__status--accepted">
        Accepted. They have until {message.offer_expires_at ? deadlineLabel(message.offer_expires_at) : 'the deadline'} to
        pay £{message.offer_amount}. It stays on sale until they do.
      </p>
    );
  }

  return (
    <div className="bubble__accepted">
      <p className="bubble__status bubble__status--accepted">
        Accepted. Pay £{message.offer_amount} by{' '}
        {message.offer_expires_at ? deadlineLabel(message.offer_expires_at) : 'the deadline'}.
      </p>
      <button className="btn-primary btn-sm" onClick={onPay}>
        Pay £{message.offer_amount}
      </button>
      <p className="bubble__note">
        It is still on sale to everyone else until you do, so it is first come first served.
      </p>
    </div>
  );
}

function ConversationRow({ conv, active, onClick }: { conv: ConversationSummary; active: boolean; onClick: () => void }) {
  return (
    <div className={`conversation-row${active ? ' conversation-row--active' : ''}`} onClick={onClick}>
      <div className="conversation-row__top">
        <p className="conversation-row__name">
          {conv.other_participant.username}
          {conv.other_participant.community_verified && (
            <span className="conversation-row__verified">Verified</span>
          )}
        </p>
        {conv.unread_count > 0 && (
          <span className="conversation-row__unread">{conv.unread_count}</span>
        )}
      </div>
      <p className="conversation-row__listing">{conv.listing.title}</p>
      <p className="conversation-row__preview">
        {conv.latest_message_preview || 'No messages yet'}
      </p>
    </div>
  );
}

function ChatPanel({
  conversationId,
  conv,
  token,
  currentUserId,
  onActivity,
}: {
  conversationId: number;
  conv: ConversationSummary | undefined;
  token: string;
  currentUserId: number;
  onActivity: () => void;
}) {
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [loading, setLoading] = useState(true);
  const [draft, setDraft] = useState('');
  const [offerMode, setOfferMode] = useState(false);
  const [offerAmount, setOfferAmount] = useState('');
  const [endorsing, setEndorsing] = useState(false);
  const [endorseError, setEndorseError] = useState('');
  const [respondingId, setRespondingId] = useState<number | null>(null);
  const bottomRef = useRef<HTMLDivElement | null>(null);
  const navigate = useNavigate();

  useEffect(() => {
    setLoading(true);
    // Fetching the conversation also marks its unread messages as read, as a
    // side effect on the backend, so there's no separate "mark read" call to
    // make, so onActivity() (which re-pulls the sidebar's unread counts) just
    // runs straight after this resolves.
    fetch(`${API}/api/conversations/${conversationId}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
      .then(res => res.json())
      .then(body => { setMessages(body.messages); setLoading(false); onActivity(); })
      .catch(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [conversationId, token]);

  useEffect(() => {
    const echo = createEcho(token);
    // Receive-only: writes go out over the REST endpoints below, not this
    // socket. The leading "." on the event name tells Echo this is a custom
    // broadcastAs() name, not a namespaced Artisan event class.
    echo.private(`conversation.${conversationId}`).listen('.message.sent', (payload: ChatMessage) => {
      setMessages(prev => {
        const exists = prev.some(m => m.id === payload.id);
        // An offer being accepted/declined re-broadcasts the SAME message id
        // with a new offer_status, so that case needs a replace in place, not
        // a second bubble.
        return exists ? prev.map(m => (m.id === payload.id ? payload : m)) : [...prev, payload];
      });
      onActivity();
    });

    return () => {
      echo.leave(`conversation.${conversationId}`);
      echo.disconnect();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [conversationId, token]);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  const post = async (body: { content: string; message_type: 'TEXT' | 'PRICE_OFFER'; offer_amount?: number }) => {
    const res = await fetch(`${API}/api/conversations/${conversationId}/messages`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify(body),
    });
    if (!res.ok) {
      let detail = `Server responded ${res.status}`;
      try {
        const errBody = await res.json();
        detail = errBody?.message || detail;
      } catch {
        // response wasn't JSON, so stick with the status code
      }
      alert(`Couldn't send that: ${detail}`);
      return;
    }
    const { data } = await res.json();
    // The websocket push for this same message may or may not have already
    // landed by the time this response comes back. Deduplicating by id in the
    // listen() callback above handles either ordering.
    setMessages(prev => (prev.some(m => m.id === data.id) ? prev : [...prev, data]));
    onActivity();
  };

  const sendText = (text: string) => {
    if (!text.trim()) return;
    post({ content: text.trim(), message_type: 'TEXT' });
    setDraft('');
  };

  const sendOffer = () => {
    const amount = parseFloat(offerAmount);
    if (!amount || amount <= 0) return;
    post({ content: `Offer: £${amount}`, message_type: 'PRICE_OFFER', offer_amount: amount });
    setOfferAmount('');
    setOfferMode(false);
  };

  const respondToOffer = async (messageId: number, accept: boolean) => {
    setRespondingId(messageId);
    try {
      const res = await fetch(`${API}/api/messages/${messageId}/respond`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ action: accept ? 'accept' : 'decline' }),
      });
      if (res.ok) {
        const { data } = await res.json();
        setMessages(prev => prev.map(m => (m.id === data.id ? data : m)));
        onActivity();
      } else {
        let detail = `Server responded ${res.status}`;
        try {
          const body = await res.json();
          detail = body?.message || detail;
        } catch {
          // response wasn't JSON, so stick with the status code
        }
        console.error('Failed to respond to offer:', detail);
        alert(`Couldn't ${accept ? 'accept' : 'decline'} this offer: ${detail}`);
      }
    } catch (err) {
      console.error('Failed to respond to offer:', err);
      alert('Could not reach the server. Is the backend running?');
    } finally {
      setRespondingId(null);
    }
  };

  const endorseOtherUser = async () => {
    if (!conv) return;
    setEndorsing(true);
    setEndorseError('');
    try {
      const res = await fetch(`${API}/api/users/${conv.other_participant.id}/endorse`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (res.ok) {
        onActivity();
      } else {
        const body = await res.json().catch(() => null);
        setEndorseError(body?.message || 'Could not endorse this user.');
      }
    } catch (err) {
      console.error('Failed to endorse user:', err);
      setEndorseError('Could not reach the server. Is the backend running?');
    } finally {
      setEndorsing(false);
    }
  };

  const suggestions = conv
    ? [
        'Hi! Is this still available?',
        'Can you tell me more about its condition?',
        conv.listing.price ? `Would you take £${Math.round(conv.listing.price * 0.9)}?` : 'Would you consider a lower price?',
      ]
    : [];

  return (
    <div className="chat">
      {conv && (
        <div className="chat__header">
          <div className="chat__header-top">
            <div>
              <p className="chat__name">
                {conv.other_participant.username}
                {conv.other_participant.community_verified && (
                  <span className="chat__verified">Verified</span>
                )}
              </p>
              <p className="chat__subject">
                {conv.listing.title} · £{conv.listing.price}
              </p>
            </div>
            {conv.has_endorsed_other ? (
              <span className="chat__endorsed">Endorsed ✓</span>
            ) : (
              <button className="btn-ghost btn-sm" onClick={endorseOtherUser} disabled={endorsing}>
                {endorsing ? 'Endorsing...' : `Endorse ${conv.other_participant.username}`}
              </button>
            )}
          </div>
          {endorseError && <p className="chat__error">{endorseError}</p>}
        </div>
      )}

      <div className="chat__body">
        {loading && <p className="text-muted">Loading...</p>}

        {!loading && messages.length === 0 && (
          <div className="chat__empty">
            <p>No messages yet. Try one of these:</p>
            <div className="chat__suggestions">
              {suggestions.map(s => (
                <button key={s} className="chat__suggestion" onClick={() => sendText(s)}>
                  {s}
                </button>
              ))}
            </div>
          </div>
        )}

        {messages.map(m => {
          const mine = m.sender_id === currentUserId;
          const isOffer = m.message_type === 'PRICE_OFFER';
          return (
            <div
              key={m.id}
              className={`bubble${mine ? ' bubble--mine' : ''}${isOffer ? ' bubble--offer' : ''}`}
            >
              <div className="bubble__body">
                {isOffer && (
                  <>
                    <p className="bubble__offer-amount">Offer: £{m.offer_amount}</p>
                    <p className="bubble__text">{m.content}</p>

                    {m.offer_status === 'PENDING' && !mine && (
                      <div className="bubble__actions">
                        <button
                          className="btn-primary btn-sm"
                          onClick={() => respondToOffer(m.id, true)}
                          disabled={respondingId === m.id}
                        >
                          {respondingId === m.id ? '...' : 'Accept'}
                        </button>
                        <button
                          className="btn-danger btn-sm"
                          onClick={() => respondToOffer(m.id, false)}
                          disabled={respondingId === m.id}
                        >
                          {respondingId === m.id ? '...' : 'Decline'}
                        </button>
                      </div>
                    )}
                    {m.offer_status === 'PENDING' && mine && (
                      <p className="bubble__status">Awaiting response...</p>
                    )}
                    {m.offer_status === 'ACCEPTED' && (
                      <AcceptedOffer
                        message={m}
                        amIBuyer={mine}
                        listing={conv?.listing}
                        onPay={() => conv && navigate(`/checkout/${conv.listing.id}`)}
                      />
                    )}
                    {m.offer_status === 'DECLINED' && (
                      <p className="bubble__status bubble__status--declined">Declined</p>
                    )}
                  </>
                )}
                {!isOffer && <p className="bubble__text">{m.content}</p>}

                {m.safety_flags && m.safety_flags.length > 0 && (
                  <SafetyWarning flags={m.safety_flags} mine={mine} />
                )}
              </div>
              <p className="bubble__time">
                {new Date(m.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
              </p>
            </div>
          );
        })}
        <div ref={bottomRef} />
      </div>

      <div className="chat__composer">
        {offerMode ? (
          <>
            <span className="chat__composer-prefix">£</span>
            <input
              className="field chat__composer-input"
              type="number"
              autoFocus
              value={offerAmount}
              onChange={e => setOfferAmount(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && sendOffer()}
            />
            <button className="btn-primary" onClick={sendOffer}>Send offer</button>
            <button className="btn-ghost" onClick={() => setOfferMode(false)}>Cancel</button>
          </>
        ) : (
          <>
            <input
              className="field chat__composer-input"
              type="text"
              placeholder="Type a message..."
              value={draft}
              onChange={e => setDraft(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && sendText(draft)}
            />
            {!conv?.am_i_seller && (
              <button className="btn-ghost" onClick={() => setOfferMode(true)}>Make offer</button>
            )}
            <button className="btn-primary" onClick={() => sendText(draft)}>Send</button>
          </>
        )}
      </div>
    </div>
  );
}

function MessagesPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const [conversations, setConversations] = useState<ConversationSummary[]>([]);
  const [loading, setLoading] = useState(true);

  const fetchConversations = () => {
    if (!user) return;
    fetch(`${API}/api/conversations`, { headers: { Accept: 'application/json', Authorization: `Bearer ${user.token}` } })
      .then(res => res.json())
      .then(body => { setConversations(body.data); setLoading(false); })
      .catch(() => setLoading(false));
  };

  useEffect(() => {
    fetchConversations();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user]);

  if (!user) {
    return <div className="page">Log in to view your messages.</div>;
  }

  const activeId = id ? Number(id) : null;
  const activeConv = conversations.find(c => c.id === activeId);

  return (
    <div className="inbox">
      <div className="inbox__list">
        <h2 className="inbox__list-title">Messages</h2>
        {loading && <p className="inbox__list-note">Loading...</p>}
        {!loading && conversations.length === 0 && (
          <p className="inbox__list-note">No conversations yet. Message a seller from a listing to start one.</p>
        )}
        {conversations.map(c => (
          <ConversationRow key={c.id} conv={c} active={c.id === activeId} onClick={() => navigate(`/messages/${c.id}`)} />
        ))}
      </div>
      <div className="inbox__thread">
        {activeId ? (
          <ChatPanel conversationId={activeId} conv={activeConv} token={user.token} currentUserId={user.id} onActivity={fetchConversations} />
        ) : (
          <div className="inbox__placeholder">Select a conversation</div>
        )}
      </div>
    </div>
  );
}

export default MessagesPage;
