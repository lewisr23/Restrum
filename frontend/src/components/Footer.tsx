import { useNavigate } from 'react-router-dom';

// Site footer. Added 2026-07-23 -- the site had no bottom-of-page presence
// at all before this, which was part of why it read as "unfinished" next to
// a real marketplace like Reverb. Deliberately narrower in scope than
// Reverb's own footer: no news section, no app-download promo, no fake
// social/legal links to pages that don't exist. Every link here goes
// somewhere real. A footer full of dead links would be a worse look than no
// footer at all -- see the whole silent-failed-button saga from earlier
// this project for exactly why that's not a hypothetical concern here.
function Footer() {
  const navigate = useNavigate();

  const linkStyle: React.CSSProperties = {
    display: 'block',
    color: '#aaa',
    fontSize: '14px',
    marginBottom: '10px',
    cursor: 'pointer',
    background: 'none',
    border: 'none',
    padding: 0,
    textAlign: 'left',
  };

  const headingStyle: React.CSSProperties = {
    margin: '0 0 14px',
    fontSize: '13px',
    color: '#666',
    textTransform: 'uppercase',
    letterSpacing: '0.08em',
  };

  return (
    <footer style={{ background: '#141414', borderTop: '1px solid #262626', marginTop: '48px' }}>
      <div
        style={{
          maxWidth: '1100px',
          margin: '0 auto',
          padding: '40px 24px',
          display: 'grid',
          gridTemplateColumns: '1.4fr 1fr 1fr',
          gap: '32px',
        }}
      >
        <div>
          <h2 style={{ margin: 0, fontSize: '20px', fontWeight: 700, letterSpacing: '-0.02em' }}>
            Tone<span style={{ color: '#4caf50' }}>Trade</span>
          </h2>
          <p style={{ margin: '10px 0 0', fontSize: '13px', color: '#888', lineHeight: '1.6', maxWidth: '320px' }}>
            A UK marketplace for buying and selling secondhand instruments and gear,
            built around trust, real condition history, and fair pricing.
          </p>
        </div>

        <div>
          <p style={headingStyle}>Marketplace</p>
          <button style={linkStyle} onClick={() => navigate('/')}>Browse gear</button>
          <button style={linkStyle} onClick={() => navigate('/create')}>Sell gear</button>
          <button style={linkStyle} onClick={() => navigate('/saved')}>Saved listings</button>
          <button style={linkStyle} onClick={() => navigate('/messages')}>Messages</button>
        </div>

        <div>
          <p style={headingStyle}>About</p>
          <p style={{ margin: '0 0 10px', fontSize: '14px', color: '#aaa', lineHeight: '1.6' }}>
            Verified sellers are confirmed through real buyer and seller endorsements,
            not self-declared badges.
          </p>
        </div>
      </div>

      <div
        style={{
          borderTop: '1px solid #222',
          padding: '16px 24px',
          maxWidth: '1100px',
          margin: '0 auto',
          fontSize: '12px',
          color: '#555',
        }}
      >
        © 2026 ToneTrade
      </div>
    </footer>
  );
}

export default Footer;
