import { Link } from 'react-router-dom';

import LegalPage, { Clause } from './LegalPage';
import { OPERATOR, PLATFORM_FEE_PERCENT, AUTO_RELEASE_DAYS, RESERVATION_MINUTES } from '../lib/legal';

// Terms of Service.
//
// Written to describe what the software actually does, clause by clause, so
// the numbers here are the numbers in config/services.php and the escrow
// described in clause 6 is the escrow implemented in EscrowService. If either
// changes, this changes with it: terms that describe a payment flow the site
// no longer runs are worse than no terms, because people rely on them.
//
// This is not legal advice and has not been reviewed by a solicitor.
function Terms() {
  return (
    <LegalPage
      title="Terms of Service"
      intro={`These terms govern your use of ${OPERATOR.tradingName}. By creating an account, listing an item or buying one, you agree to them. Please read clause 7 in particular, which explains what you can and cannot expect when you buy from a private seller.`}
    >
      <Clause n={1} title="Who we are">
        <p>
          {OPERATOR.website} is operated by {OPERATOR.legalName}, trading as{' '}
          {OPERATOR.tradingName}. You can reach us at {OPERATOR.contactEmail}.
          Our address for legal notices is {OPERATOR.postalAddress}.
        </p>
        <p>
          {OPERATOR.tradingName} is a marketplace for secondhand musical
          instruments and audio equipment in the United Kingdom. We put buyers
          and sellers in touch with each other and handle the payment between
          them. We do not own, inspect, store, test or ship any of the items
          listed on the site.
        </p>
      </Clause>

      <Clause n={2} title="Using the site">
        <p>
          You must be at least 18 and resident in the United Kingdom to buy or
          sell here. You need an account to do either, and you are responsible
          for what happens under it, so keep your password to yourself and tell
          us promptly if you think someone else has it.
        </p>
        <p>
          Give accurate details when you register. One person, one account. We
          may suspend or close accounts that break these terms, and clause 13
          explains how.
        </p>
      </Clause>

      <Clause n={3} title="Listing an item">
        <p>When you list something you confirm that:</p>
        <ul>
          <li>you own it, or are otherwise entitled to sell it;</li>
          <li>
            your description, photographs, condition rating and any gear
            history you add are honest and accurate, including any faults,
            damage, missing parts or non original components;
          </li>
          <li>it is not stolen, counterfeit, or a replica sold as genuine;</li>
          <li>selling it does not break any law or infringe anyone's rights.</li>
        </ul>
        <p>
          If you give a serial number, we check it against our{' '}
          <Link to="/stolen">stolen gear register</Link>. If it matches gear
          reported stolen, the listing cannot be bought until we have reviewed
          it, and we may share the details with the police. A match is not an
          accusation: serial numbers do sometimes coincide, and a person looks
          at every match before anything is decided.
        </p>
        <p>
          Prices are in pounds sterling and include everything you are charging
          for the item itself. Set your postage cost on the listing, or mark it
          collection only. The buyer pays the item price and the postage
          together, we hold both, and the postage reaches you in full: our fee
          is charged on the item alone.
        </p>
        <p>
          The postage you state is what you are agreeing to charge, so quote
          for a courier that will actually carry the item. You cannot ask the
          buyer for more once they have paid.
        </p>
        <p>
          You keep ownership of the photographs, audio and video you upload. By
          uploading them you give us permission to display and reproduce them
          on the site and in material promoting the site, for as long as your
          listing is up and for a reasonable period afterwards.
        </p>
        <p>
          If you are selling in the course of a business rather than as a
          private individual, you must say so in your listing. Your buyer then
          has consumer rights against you that they would not have when buying
          privately, and it is your responsibility to honour them.
        </p>
      </Clause>

      <Clause n={4} title="Buying an item">
        <p>
          When you pay for an item, the contract of sale is between you and the
          seller. It is not with us. We are not the seller, not an agent
          selling on the seller's behalf, and not a party to the contract,
          except that we collect the payment as described in clause 6.
        </p>
        <p>
          Starting a checkout holds the item for you for{' '}
          {RESERVATION_MINUTES} minutes so that nobody else can buy it while
          you are paying. If you do not complete the payment in that time, the
          item goes back on sale and you are free to start again if it is still
          available.
        </p>
      </Clause>

      <Clause n={5} title="Fees">
        <p>
          Buyers pay the listed price and nothing more. Browsing, listing and
          messaging are free.
        </p>
        <p>
          Sellers pay a commission of {PLATFORM_FEE_PERCENT}% of the sale
          price, deducted from the amount paid out. The commission that applies
          to a sale is the one in force when the buyer paid, and it is recorded
          against the order, so later changes to our fees never change what you
          are owed on a sale that has already happened. We pay the card
          processing charges out of our commission rather than passing them on
          to you.
        </p>
      </Clause>

      <Clause n={6} title="How payment works, and what we do with the money">
        <p>
          Payments are processed by Stripe. Your card details go directly to
          Stripe and are never seen or stored by us.
        </p>
        <p>
          When you pay, the money is collected by us as the seller's agent for
          the purpose of collecting payment. Two things follow from that, and
          both matter. Your obligation to pay the seller is discharged the
          moment we receive the money, so if we fail to pass it on, that is our
          problem and not yours. And the money is the seller's, not ours, held
          on their behalf until the conditions below are met.
        </p>
        <p>We hold the payment until the earliest of:</p>
        <ul>
          <li>you confirm that the item has arrived and is as described;</li>
          <li>
            {AUTO_RELEASE_DAYS} days have passed since payment and you have
            neither confirmed nor told us there is a problem; or
          </li>
          <li>we resolve a problem you have raised.</li>
        </ul>
        <p>
          Confirming that an item has arrived releases the money to the seller
          and cannot be undone, so only confirm once you have the item and have
          checked it. If something is wrong, use clause 8 instead.
        </p>
        <p>
          Sellers are paid into a Stripe account in their own name. To receive
          money you must complete Stripe's identity checks and accept the{' '}
          <a href="https://stripe.com/gb/legal/connect-account" target="_blank" rel="noreferrer noopener">
            Stripe Connected Account Agreement
          </a>
          . Until Stripe has verified you, buyers cannot check out on your
          listings. Payout timings after we release the money are Stripe's, not
          ours.
        </p>
        <p>
          We do not pay interest on money held, and we do not hold it as a
          bank. We are not authorised by the Financial Conduct Authority, and
          collecting payment as an agent for a seller in this way does not
          require authorisation.
        </p>
      </Clause>

      <Clause n={7} title="Your rights when something is wrong">
        <p>
          This clause is the one worth reading twice, because buying from a
          private seller is genuinely different from buying from a shop.
        </p>
        <p>
          Most sellers here are private individuals. When you buy from a
          private seller, the Consumer Rights Act 2015 does not apply. In
          particular there is no legal requirement that the item be of
          satisfactory quality or fit for purpose, and you have no automatic
          14 day right to change your mind and return it. Those rights exist
          only when you buy from a trader.
        </p>
        <p>What you do have when buying privately is the right to expect that:</p>
        <ul>
          <li>
            the item matches the description it was sold under, and misleading
            you about it, whether deliberately or carelessly, is
            misrepresentation;
          </li>
          <li>the seller actually owns it and can pass good title to you.</li>
        </ul>
        <p>
          This is why we hold the money. The protection we offer is practical
          rather than legal: if an item never arrives, or turns out to be
          materially different from its description, the money has not reached
          the seller yet and we can return it to you.
        </p>
        <p>
          If you bought from a seller who is trading as a business, your usual
          consumer rights apply against that seller in full, and nothing here
          limits them.
        </p>
      </Clause>

      <Clause n={8} title="Problems, refunds and disputes">
        <p>
          Tell the seller first. Most problems are a misunderstanding about
          postage or condition, and messaging them is usually faster than
          anything we can do.
        </p>
        <p>
          If that does not resolve it, contact us at {OPERATOR.contactEmail}{' '}
          before you confirm receipt and before the {AUTO_RELEASE_DAYS} day
          period runs out. Tell us the order number and what is wrong. While we
          are looking at it, the money stays where it is.
        </p>
        <p>
          Where we are satisfied that the item never arrived, or is materially
          not as described, we will refund you in full to the card you paid
          with. Where we are satisfied that it did arrive as described, we will
          release the money to the seller. We will decide reasonably on the
          evidence you both give us, and we will tell you both what we decided.
          Our decision does not affect either side's right to pursue the matter
          through the courts.
        </p>
        <p>
          Please do not raise a chargeback with your bank before talking to us.
          A chargeback takes the decision out of everyone's hands, costs us a
          fee whatever the outcome, and is slower than a refund we make
          ourselves.
        </p>
        <p>
          Sellers: if an item is returned to you following a refund, you are
          entitled to have it back in the condition it was sent, and you should
          tell us if it is not.
        </p>
      </Clause>

      <Clause n={9} title="Things you must not do">
        <ul>
          <li>
            Arrange payment outside {OPERATOR.tradingName} for an item listed
            here. It removes the buyer's protection, which is the entire point
            of the site, and it is the most common way people get defrauded on
            marketplaces.
          </li>
          <li>List or sell anything you are not entitled to sell.</li>
          <li>
            Use the messaging feature to harass anyone, to advertise, or to
            send anyone anything unlawful.
          </li>
          <li>
            Manipulate endorsements or feedback, including by arranging them
            with people you have not genuinely dealt with.
          </li>
          <li>
            Interfere with the site's operation, or try to access accounts,
            data or systems that are not yours.
          </li>
          <li>Scrape the site or reuse its content commercially without asking us.</li>
        </ul>
      </Clause>

      <Clause n={10} title="Tax">
        <p>
          What you owe is between you and HMRC. Selling the odd piece of gear
          you no longer need is not usually trading and not usually taxable,
          but buying to resell generally is, and the line is not one we can
          draw for you.
        </p>
        <p>
          We are required to collect information about sellers and report it to
          HMRC each year under the UK's digital platform reporting rules. That
          reporting covers sellers above the thresholds those rules set, and
          the information reported is the seller's identifying details and
          what they sold through the platform. We will tell you what we have
          reported about you.
        </p>
      </Clause>

      <Clause n={11} title="What we are and are not responsible for">
        <p>
          We are responsible for running the site and for handling payments as
          described in clause 6. We are not responsible for the items
          themselves, for the accuracy of listings, for whether a seller sends
          what they promised, or for anything that happens when you meet
          someone to collect an item.
        </p>
        <p>
          The price context shown on listings is an estimate generated from
          limited data. It is a rough guide, not a valuation, and you should
          not rely on it as one.
        </p>
        <p>
          We do not exclude or limit our liability for death or personal injury
          caused by our negligence, for fraud or fraudulent
          misrepresentation, or for anything else that cannot lawfully be
          excluded. Subject to that, we are not liable for losses that were not
          reasonably foreseeable, and our total liability to you in connection
          with any one order is limited to the value of that order.
        </p>
        <p>
          We try to keep the site available, but we do not promise it will be.
          We may change, suspend or withdraw features, and we will give
          reasonable notice where a change affects you materially.
        </p>
      </Clause>

      <Clause n={12} title="Your privacy">
        <p>
          What we do with your personal data is set out in our{' '}
          <Link to="/privacy">Privacy Policy</Link>, which forms part of these
          terms.
        </p>
      </Clause>

      <Clause n={13} title="Suspension and closing your account">
        <p>
          You can close your account at any time by contacting us. Orders
          already in progress have to finish first, because there is money
          involved and both sides are entitled to see it through.
        </p>
        <p>
          We may suspend or close your account if you break these terms, if we
          reasonably suspect fraud or illegal activity, or if we are required
          to. Where we can, we will tell you why and give you a chance to put
          it right. We will still pay you anything you are properly owed on
          completed sales.
        </p>
      </Clause>

      <Clause n={14} title="Changes to these terms">
        <p>
          We may update these terms. If a change materially affects you, we
          will give you at least 30 days' notice by email or on the site before
          it takes effect. Changes never apply retrospectively to an order
          already placed. The version in force when you paid is the version
          that governs that order.
        </p>
      </Clause>

      <Clause n={15} title="Law and disputes">
        <p>
          These terms are governed by the law of England and Wales, and the
          courts of England and Wales have jurisdiction. If you live in
          Scotland or Northern Ireland, you may also bring proceedings in your
          own country's courts.
        </p>
        <p>
          If you have a complaint about us, rather than about a seller, email{' '}
          {OPERATOR.contactEmail} and we will reply within five working days.
        </p>
      </Clause>
    </LegalPage>
  );
}

export default Terms;
