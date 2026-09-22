// Everything in the Terms and the Privacy Policy that is about the business
// rather than about the product.
//
// One file, because the same handful of facts appear in both documents and in
// several places within each. Two copies of a contact address is one copy that
// goes stale.

// Fields still to be filled in are set to this rather than left blank, so a
// missing value is loud on the page instead of rendering as an empty gap that
// nobody notices until a customer asks who they are actually dealing with.
export const TO_BE_CONFIRMED = 'TO BE CONFIRMED';

export const OPERATOR = {
  /** What the site is called. */
  tradingName: 'Restrum',

  /**
   * The person legally responsible. A sole trader has to publish their own
   * name alongside the trading name: the Companies Act 2006 requires it of
   * anyone trading under a name that is not their own, and the Ecommerce
   * Regulations 2002 require it of anyone selling online. "Restrum" on its
   * own does not satisfy either.
   */
  legalName: 'Lewis Steven Robinson',

  /**
   * An address where legal documents can be served. It does not have to be
   * where you live or work, and a service address from a formation agent is
   * the usual way to avoid publishing a home address. It cannot be omitted.
   */
  postalAddress: TO_BE_CONFIRMED,

  /**
   * Has to be a real monitored mailbox. Both documents point people here to
   * exercise data rights and to raise problems with an order, and the UK GDPR
   * gives you one month to answer a rights request.
   */
  contactEmail: 'restrumsupport@gmail.com',

  /** For data protection questions specifically. Can be the same mailbox. */
  privacyEmail: 'restrumsupport@gmail.com',

  website: 'https://restrum.uk',

  /** Shown on both documents, and the date people rely on when they disagree. */
  lastUpdated: '13 September 2026',
} as const;

/** The platform's cut, kept in step with STRIPE_PLATFORM_FEE_PERCENT. */
export const PLATFORM_FEE_PERCENT = 5;

/** Matches STRIPE_AUTO_RELEASE_DAYS in config/services.php. */
export const AUTO_RELEASE_DAYS = 14;

/** Matches STRIPE_RESERVATION_MINUTES in config/services.php. */
export const RESERVATION_MINUTES = 30;

/** Matches STRIPE_OFFER_HOURS in config/services.php. */
export const OFFER_HOURS = 48;

/** Which operator details are still unset, for the draft warning on the page. */
export function missingOperatorDetails(): string[] {
  return Object.entries(OPERATOR)
    .filter(([, value]) => value === TO_BE_CONFIRMED)
    .map(([key]) => key);
}
