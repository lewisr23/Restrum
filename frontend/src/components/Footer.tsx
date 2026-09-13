import { useNavigate } from 'react-router-dom';

// Site footer. Deliberately narrower in scope than something like Reverb's:
// no news section, no app download promo, no social or legal links to pages
// that don't exist. Every link here goes somewhere real, since a footer full
// of dead links looks worse than no footer at all.
function Footer() {
  const navigate = useNavigate();

  return (
    <footer className="site-footer">
      <div className="site-footer__inner">
        <div>
          <h2 className="site-footer__brand">
            Re<span className="site-footer__brand-accent">strum</span>
          </h2>
          <p className="site-footer__blurb">
            A UK marketplace for buying and selling secondhand instruments and gear,
            built around trust, real condition history, and fair pricing.
          </p>
        </div>

        <div>
          <p className="site-footer__heading">Marketplace</p>
          <button className="site-footer__link" onClick={() => navigate('/')}>Browse gear</button>
          <button className="site-footer__link" onClick={() => navigate('/create')}>Sell gear</button>
          <button className="site-footer__link" onClick={() => navigate('/saved')}>Saved listings</button>
          <button className="site-footer__link" onClick={() => navigate('/messages')}>Messages</button>
        </div>

        <div>
          <p className="site-footer__heading">About</p>
          <p className="site-footer__note">
            Verified sellers are confirmed through real buyer and seller endorsements,
            not badges people hand themselves.
          </p>
        </div>
      </div>

      <div className="site-footer__legal">© 2026 Restrum</div>
    </footer>
  );
}

export default Footer;
