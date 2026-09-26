import { Link } from 'react-router-dom';

import LegalPage, { Clause } from './LegalPage';
import { OPERATOR, AUTO_RELEASE_DAYS } from '../lib/legal';

// Privacy Policy.
//
// Every category in clause 2 is a real column or file this application
// actually stores, and clause 4 lists the processors it actually sends data
// to. That correspondence is the point: a privacy policy describing data you
// do not hold is as wrong as one omitting data you do, and the second kind is
// the kind the ICO hears about.
//
// Two things here describe the production deployment rather than the code, so
// they need checking whenever that changes: the hosting location in clause 4
// and the transfers in clause 5.
//
// This is not legal advice and has not been reviewed by a solicitor.
function Privacy() {
  return (
    <LegalPage
      title="Privacy Policy"
      intro={`This explains what ${OPERATOR.tradingName} does with your personal data, why, and what you can ask us to do about it. We have tried to write it in plain English rather than in the language of the regulation it is written to satisfy.`}
    >
      <Clause n={1} title="Who is responsible for your data">
        <p>
          {OPERATOR.legalName}, trading as {OPERATOR.tradingName}, is the
          controller of the personal data described here. Our address is{' '}
          {OPERATOR.postalAddress} and you can reach us about anything in this
          policy at {OPERATOR.privacyEmail}.
        </p>
        <p>
          Stripe is a separate controller of the data it collects to verify
          sellers and to process payments. When Stripe checks a seller's
          identity it does so for its own legal obligations, not on our
          instructions, and its own{' '}
          <a href="https://stripe.com/gb/privacy" target="_blank" rel="noreferrer noopener">
            privacy policy
          </a>{' '}
          governs that.
        </p>
      </Clause>

      <Clause n={2} title="What we collect">
        <p>
          <strong>Your account.</strong> Username, email address, a hashed
          version of your password, and optionally the town or city you are in
          and a short profile description. We never store your password
          itself, only a hash it cannot be recovered from.
        </p>
        <p>
          <strong>What you put on the site.</strong> Your listings, including
          descriptions, prices, locations, condition ratings, gear history
          entries, serial numbers, and any photographs, audio or video you
          upload.
        </p>
        <p>
          <strong>Stolen gear reports.</strong> If you report gear stolen: its
          serial number, brand and description, when and where it was taken,
          and your police crime reference if you give one. Anyone checking a
          serial on the register can see the description, brand, place and
          dates. They never see who reported it or the crime reference.
        </p>
        <p>
          <strong>Photos used to fill in a listing.</strong> If you use "Start
          from your photos", those photos are sent to Anthropic to draft the
          listing. We do not keep them for this purpose; they only stay on the
          site if you go on to post the listing with them.
        </p>
        <p>
          <strong>Questions to the gear adviser.</strong> What you type into
          the adviser is sent to Anthropic to answer it. We do not store the
          conversation.
        </p>
        <p>
          <strong>Messages.</strong> The content of conversations between you
          and the person on the other side of a listing, including price
          offers.
        </p>
        <p>
          <strong>Trust signals.</strong> Who has endorsed you, who you have
          endorsed, and who follows whom.
        </p>
        <p>
          <strong>Orders.</strong> What was bought, from whom, for how much,
          our commission, and the times at which the order was paid, confirmed,
          paid out or refunded. We also store the reference numbers Stripe
          gives us for the payment and the payout.
        </p>
        <p>
          <strong>What we deliberately do not collect.</strong> We never
          receive or store card numbers, bank account details, dates of birth,
          or identity documents. Those go from your browser to Stripe directly.
          For sellers we store only Stripe's account reference and two flags
          telling us whether Stripe is willing to pay you.
        </p>
        <p>
          <strong>Technical data.</strong> Our servers record IP addresses,
          browser user agent strings and request details in access and error
          logs, which is ordinary web server behaviour and is how we
          investigate abuse and faults.
        </p>
      </Clause>

      <Clause n={3} title="Why we use it, and our lawful basis">
        <p>
          <strong>To run your account and the sales you make through it.</strong>{' '}
          Registering you, showing your listings, delivering your messages,
          taking payment, holding it for the {AUTO_RELEASE_DAYS} day protection
          period and paying sellers. Lawful basis: performance of our contract
          with you.
        </p>
        <p>
          <strong>To keep the marketplace safe and honest.</strong> Detecting
          fraud, investigating reports, resolving disputes about orders,
          securing the site, and defending legal claims. Lawful basis: our
          legitimate interests in operating a marketplace people can trust,
          balanced against your interest in not being monitored beyond what
          that needs.
        </p>
        <p>
          <strong>To run the stolen gear register.</strong> Checking serial
          numbers on listings against reported thefts, holding a matching
          listing while we review it, and telling the person who reported the
          theft. Lawful basis: our legitimate interest, and theirs, in stolen
          instruments not being resold here.
        </p>
        <p>
          <strong>To draft a listing from your photos, or answer an adviser
          question.</strong> Only when you ask for it. Lawful basis:
          performance of our contract with you.
        </p>
        <p>
          <strong>To meet legal obligations.</strong> Keeping business and tax
          records, and reporting seller information to HMRC under the UK's
          digital platform reporting rules. Lawful basis: legal obligation.
        </p>
        <p>
          <strong>To contact you about your account.</strong> Password resets,
          order updates, changes to these documents. Lawful basis: performance
          of our contract. We do not send marketing email, and if we ever do it
          will be because you asked for it and it will have an unsubscribe link.
        </p>
      </Clause>

      <Clause n={4} title="Who sees it">
        <p>
          <strong>Other people using the site.</strong> Your username, your
          town or city, your profile description, your listings and your
          endorsement count are public and visible to anyone, signed in or not.
          Your email address is not. Messages are visible to the other person
          in the conversation.
        </p>
        <p>
          <strong>Stripe</strong> (Stripe Payments UK Ltd and its group), for
          processing payments, verifying sellers and making payouts.
        </p>
        <p>
          <strong>Anthropic</strong> (Anthropic PBC), which provides the AI
          model behind the gear adviser and drafting a listing from photos. It
          receives only what you send through those two features. Its{' '}
          <a href="https://www.anthropic.com/legal/privacy" target="_blank" rel="noreferrer noopener">
            privacy policy
          </a>{' '}
          explains how it handles that.
        </p>
        <p>
          <strong>Our hosting and network providers.</strong> The site runs on
          servers hosted by Contabo GmbH in France, behind Cloudflare, which
          handles our domain and sits in front of the site. Cloudflare sees the
          IP addresses of people visiting.
        </p>
        <p>
          <strong>Whoever reported a theft</strong>, if your listing's serial
          number matches their report. They are told which listing matched. It
          is public anyway, and nothing else about you is passed to them.
        </p>
        <p>
          <strong>HMRC</strong>, where the digital platform reporting rules
          require it, <strong>the police</strong>, where they are investigating
          a theft or fraud connected to the site, and any other authority where
          we are legally obliged to disclose.
        </p>
        <p>
          We do not sell your personal data, and we do not share it with
          advertisers or data brokers.
        </p>
      </Clause>

      <Clause n={5} title="Where your data goes">
        <p>
          Our servers are in France. Stripe and Cloudflare are international
          and may process data outside the UK, including in the United States.
          Anthropic is based in the United States and processes what it
          receives there.
          Where that happens, the transfer is covered by UK adequacy
          regulations or by the International Data Transfer Agreement, which is
          the safeguard UK law requires.
        </p>
      </Clause>

      <Clause n={6} title="How long we keep it">
        <p>
          <strong>Your account and profile</strong>, for as long as your
          account is open. If you close it, we delete or anonymise this within
          30 days, apart from what the next paragraph covers.
        </p>
        <p>
          <strong>Orders and payment records</strong>, for six years from the
          end of the tax year they fall in, because HMRC requires business
          records to be kept that long. This is why closing your account does
          not erase your order history, and why we cannot agree to delete it on
          request.
        </p>
        <p>
          <strong>Messages about an order</strong>, alongside that order, for
          the same period, because they are the evidence if a dispute is raised
          later. Messages not connected to an order are deleted with your
          account.
        </p>
        <p>
          <strong>Stolen gear reports</strong>, for as long as your account is
          open. Marking one recovered stops it matching listings but keeps the
          record, because it may be needed if a match is being reviewed.
          Closing your account deletes them.
        </p>
        <p>
          <strong>Server logs</strong>, for 90 days.
        </p>
      </Clause>

      <Clause n={7} title="Your rights">
        <p>Under UK data protection law you can ask us to:</p>
        <ul>
          <li>give you a copy of the personal data we hold about you;</li>
          <li>correct anything that is wrong;</li>
          <li>
            delete it, though clause 6 explains where the law requires us to
            keep records anyway;
          </li>
          <li>restrict or stop a particular use of it;</li>
          <li>
            send it to you, or to another service, in a machine readable form;
          </li>
          <li>
            stop relying on legitimate interests, where you object and we
            cannot show a compelling reason to continue.
          </li>
        </ul>
        <p>
          Email {OPERATOR.privacyEmail} and we will answer within one month.
          There is no charge. We may ask you to confirm who you are first,
          which is a protection for you rather than an obstacle.
        </p>
        <p>
          If you are unhappy with how we have handled your data you can
          complain to the Information Commissioner's Office at{' '}
          <a href="https://ico.org.uk/make-a-complaint/" target="_blank" rel="noreferrer noopener">
            ico.org.uk
          </a>{' '}
          or on 0303 123 1113. We would rather you came to us first so we can
          put it right.
        </p>
      </Clause>

      <Clause n={8} title="Cookies and what is stored on your device">
        <p>
          When you sign in, we store a login token in your browser's local
          storage so that you stay signed in between pages. It is strictly
          necessary for the site to work and is removed when you log out.
        </p>
        <p>
          We do not use analytics, advertising trackers or third party
          profiling cookies, which is why there is no cookie banner on this
          site. If that ever changes we will ask for your consent first rather
          than assuming it.
        </p>
      </Clause>

      <Clause n={9} title="Security">
        <p>
          Passwords are stored as bcrypt hashes and never in a readable form.
          Traffic to the site is encrypted in transit. Card and bank details
          never reach our servers at all, which is the single most useful thing
          we can tell you about how they are protected.
        </p>
        <p>
          No system is perfectly secure. If a breach happens that puts your
          rights at risk we will tell you, and we will report it to the ICO
          within 72 hours as the law requires.
        </p>
      </Clause>

      <Clause n={10} title="Children">
        <p>
          {OPERATOR.tradingName} is for adults. You must be 18 or over to have
          an account. If we learn that an account belongs to someone younger we
          will close it and delete the data.
        </p>
      </Clause>

      <Clause n={11} title="Changes">
        <p>
          If we change this policy we will update the date at the top, and we
          will tell you by email before any change that materially affects how
          we use data we already hold about you.
        </p>
        <p>
          Our <Link to="/terms">Terms of Service</Link> cover everything else
          about using the site.
        </p>
      </Clause>
    </LegalPage>
  );
}

export default Privacy;
