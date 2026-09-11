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
  created_at: string;
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
                      <p className="bubble__status bubble__status--accepted">Accepted</p>
                    )}
                    {m.offer_status === 'DECLINED' && (
                      <p className="bubble__status bubble__status--declined">Declined</p>
                    )}
                  </>
                )}
                {!isOffer && <p className="bubble__text">{m.content}</p>}
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
