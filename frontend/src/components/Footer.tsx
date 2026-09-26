import { useNavigate } from 'react-router-dom';

// Site footer. Deliberately narrower in scope than something like Reverb's:
// no news section, no app download promo, no social links. Every link here
// goes somewhere real, since a footer full of dead links looks worse than no
// footer at all.
//
// The legal links are in the bottom bar rather than in a column of their own,
// which is where people look for them and, more to the point, where Stripe
// looks for them: a business website has to make its terms and its privacy
// policy reachable from every page.
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
          <button className="site-footer__link" onClick={() => navigate('/orders')}>Your orders</button>
          <button className="site-footer__link" onClick={() => navigate('/stolen')}>Stolen gear check</button>
          <button className="site-footer__link" onClick={() => navigate('/messages')}>Messages</button>
        </div>

        <div>
          <p className="site-footer__heading">About</p>
          <button className="site-footer__link" onClick={() => navigate('/about')}>What Restrum is</button>
          <button className="site-footer__link" onClick={() => navigate('/faq')}>Questions, answered</button>
          <p className="site-footer__note">
            Verified sellers are confirmed through real buyer and seller endorsements,
            not badges people hand themselves.
          </p>
        </div>
      </div>

      <div className="site-footer__legal">
        <span>© 2026 Restrum</span>
        <span className="site-footer__legal-links">
          <button className="site-footer__legal-link" onClick={() => navigate('/terms')}>Terms of Service</button>
          <button className="site-footer__legal-link" onClick={() => navigate('/privacy')}>Privacy Policy</button>
        </span>
      </div>
    </footer>
  );
}

export default Footer;
