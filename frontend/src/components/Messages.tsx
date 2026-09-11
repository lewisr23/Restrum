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
    <div
      onClick={onClick}
      style={{
        padding: '12px 16px',
        cursor: 'pointer',
        background: active ? '#1a1a1a' : 'transparent',
        borderBottom: '1px solid #222',
      }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <p style={{ margin: 0, fontWeight: 600, fontSize: '14px' }}>
          {conv.other_participant.username}
          {conv.other_participant.community_verified && <span style={{ color: '#4caf50', marginLeft: '6px', fontSize: '11px' }}>Verified</span>}
        </p>
        {conv.unread_count > 0 && (
          <span style={{ background: '#4caf50', color: 'white', borderRadius: '10px', padding: '1px 7px', fontSize: '11px' }}>
            {conv.unread_count}
          </span>
        )}
      </div>
      <p style={{ margin: '2px 0 0', color: '#888', fontSize: '12px' }}>{conv.listing.title}</p>
      <p style={{ margin: '2px 0 0', color: '#666', fontSize: '12px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
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
    // side effect on the backend - there's no separate "mark read" call to
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
        // with a new offer_status - that case needs an in-place replace, not
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
        // response wasn't JSON -- stick with the status code
      }
      alert(`Couldn't send that: ${detail}`);
      return;
    }
    const { data } = await res.json();
    // The websocket push for this same message may or may not have already
    // landed by the time this response comes back - the dedup-by-id in the
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
          // response wasn't JSON -- stick with the status code
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
    <div style={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
      {conv && (
        <div style={{ padding: '16px', borderBottom: '1px solid #333' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '12px' }}>
            <div>
              <p style={{ margin: 0, fontWeight: 600 }}>
                {conv.other_participant.username}
                {conv.other_participant.community_verified && <span style={{ color: '#4caf50', marginLeft: '8px', fontSize: '13px' }}>Verified</span>}
              </p>
              <p style={{ margin: 0, color: '#888', fontSize: '13px' }}>
                {conv.listing.title} · £{conv.listing.price}
              </p>
            </div>
            {conv.has_endorsed_other ? (
              <span style={{ fontSize: '12px', color: '#4caf50', whiteSpace: 'nowrap' }}>Endorsed ✓</span>
            ) : (
              <button
                onClick={endorseOtherUser}
                disabled={endorsing}
                style={{ padding: '6px 12px', background: 'none', color: '#4caf50', border: '1px solid #4caf50', borderRadius: '4px', cursor: 'pointer', fontSize: '12px', whiteSpace: 'nowrap' }}
              >
                {endorsing ? 'Endorsing...' : `Endorse ${conv.other_participant.username}`}
              </button>
            )}
          </div>
          {endorseError && <p style={{ margin: '6px 0 0', color: '#f44', fontSize: '12px' }}>{endorseError}</p>}
        </div>
      )}

      <div style={{ flex: 1, overflowY: 'auto', padding: '16px', display: 'flex', flexDirection: 'column', gap: '8px' }}>
        {loading && <p style={{ color: '#888' }}>Loading...</p>}

        {!loading && messages.length === 0 && (
          <div style={{ color: '#888' }}>
            <p style={{ fontSize: '14px' }}>No messages yet. Try one of these:</p>
            <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', maxWidth: '360px' }}>
              {suggestions.map(s => (
                <button
                  key={s}
                  onClick={() => sendText(s)}
                  style={{ textAlign: 'left', padding: '10px 14px', background: '#1a1a1a', border: '1px solid #444', color: '#ccc', borderRadius: '8px', cursor: 'pointer' }}
                >
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
            <div key={m.id} style={{ alignSelf: mine ? 'flex-end' : 'flex-start', maxWidth: '70%' }}>
              <div
                style={{
                  background: isOffer ? '#2e4d2e' : mine ? '#4caf50' : '#1a1a1a',
                  border: isOffer ? '1px solid #4caf50' : mine ? 'none' : '1px solid #333',
                  color: mine && !isOffer ? '#111' : 'white',
                  padding: '10px 14px',
                  borderRadius: '12px',
                  fontSize: '14px',
                }}
              >
                {isOffer && (
                  <>
                    <p style={{ margin: '0 0 4px', fontWeight: 700, color: '#4caf50' }}>Offer: £{m.offer_amount}</p>
                    <p style={{ margin: 0 }}>{m.content}</p>

                    {m.offer_status === 'PENDING' && !mine && (
                      <div style={{ display: 'flex', gap: '6px', marginTop: '8px' }}>
                        <button
                          onClick={() => respondToOffer(m.id, true)}
                          disabled={respondingId === m.id}
                          style={{ padding: '4px 10px', background: '#4caf50', color: 'white', border: 'none', borderRadius: '4px', cursor: 'pointer', fontSize: '12px' }}
                        >
                          {respondingId === m.id ? '...' : 'Accept'}
                        </button>
                        <button
                          onClick={() => respondToOffer(m.id, false)}
                          disabled={respondingId === m.id}
                          style={{ padding: '4px 10px', background: 'none', color: '#f44', border: '1px solid #f44', borderRadius: '4px', cursor: 'pointer', fontSize: '12px' }}
                        >
                          {respondingId === m.id ? '...' : 'Decline'}
                        </button>
                      </div>
                    )}
                    {m.offer_status === 'PENDING' && mine && (
                      <p style={{ margin: '6px 0 0', fontSize: '12px', color: '#aaa' }}>Awaiting response...</p>
                    )}
                    {m.offer_status === 'ACCEPTED' && (
                      <p style={{ margin: '6px 0 0', fontSize: '12px', color: '#4caf50', fontWeight: 600 }}>Accepted</p>
                    )}
                    {m.offer_status === 'DECLINED' && (
                      <p style={{ margin: '6px 0 0', fontSize: '12px', color: '#f44', fontWeight: 600 }}>Declined</p>
                    )}
                  </>
                )}
                {!isOffer && <p style={{ margin: 0 }}>{m.content}</p>}
              </div>
              <p style={{ margin: '2px 4px 0', fontSize: '11px', color: '#666', textAlign: mine ? 'right' : 'left' }}>
                {new Date(m.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
              </p>
            </div>
          );
        })}
        <div ref={bottomRef} />
      </div>

      <div style={{ borderTop: '1px solid #333', padding: '12px 16px' }}>
        {offerMode ? (
          <div style={{ display: 'flex', gap: '8px' }}>
            <span style={{ alignSelf: 'center', color: '#888' }}>£</span>
            <input
              type="number"
              autoFocus
              value={offerAmount}
              onChange={e => setOfferAmount(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && sendOffer()}
              style={{ flex: 1, padding: '10px', background: '#1a1a1a', border: '1px solid #444', color: 'white', borderRadius: '4px' }}
            />
            <button onClick={sendOffer} style={{ padding: '10px 16px', background: '#4caf50', color: 'white', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
              Send offer
            </button>
            <button onClick={() => setOfferMode(false)} style={{ padding: '10px 16px', background: 'none', color: '#aaa', border: '1px solid #444', borderRadius: '4px', cursor: 'pointer' }}>
              Cancel
            </button>
          </div>
        ) : (
          <div style={{ display: 'flex', gap: '8px' }}>
            <input
              type="text"
              placeholder="Type a message..."
              value={draft}
              onChange={e => setDraft(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && sendText(draft)}
              style={{ flex: 1, padding: '10px', background: '#1a1a1a', border: '1px solid #444', color: 'white', borderRadius: '4px' }}
            />
            {!conv?.am_i_seller && (
              <button onClick={() => setOfferMode(true)} style={{ padding: '10px 16px', background: 'none', color: '#4caf50', border: '1px solid #4caf50', borderRadius: '4px', cursor: 'pointer' }}>
                Make offer
              </button>
            )}
            <button onClick={() => sendText(draft)} style={{ padding: '10px 20px', background: '#4caf50', color: 'white', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
              Send
            </button>
          </div>
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
    return <div style={{ color: 'white', padding: '24px' }}>Log in to view your messages.</div>;
  }

  const activeId = id ? Number(id) : null;
  const activeConv = conversations.find(c => c.id === activeId);

  return (
    <div style={{ display: 'flex', height: 'calc(100vh - 90px)', color: 'white' }}>
      <div style={{ width: '320px', borderRight: '1px solid #333', overflowY: 'auto', flexShrink: 0 }}>
        <h2 style={{ padding: '16px', margin: 0, fontSize: '18px' }}>Messages</h2>
        {loading && <p style={{ color: '#888', padding: '0 16px' }}>Loading...</p>}
        {!loading && conversations.length === 0 && (
          <p style={{ color: '#666', padding: '0 16px', fontSize: '14px' }}>No conversations yet. Message a seller from a listing to start one.</p>
        )}
        {conversations.map(c => (
          <ConversationRow key={c.id} conv={c} active={c.id === activeId} onClick={() => navigate(`/messages/${c.id}`)} />
        ))}
      </div>
      <div style={{ flex: 1 }}>
        {activeId ? (
          <ChatPanel conversationId={activeId} conv={activeConv} token={user.token} currentUserId={user.id} onActivity={fetchConversations} />
        ) : (
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%', color: '#666' }}>
            Select a conversation
          </div>
        )}
      </div>
    </div>
  );
}

export default MessagesPage;
