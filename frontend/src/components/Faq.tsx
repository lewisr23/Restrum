import { useState } from 'react';
import { Link } from 'react-router-dom';

import { OPERATOR, PLATFORM_FEE_PERCENT, AUTO_RELEASE_DAYS, RESERVATION_MINUTES, OFFER_HOURS } from '../lib/legal';

// Frequently asked questions.
//
// Grouped by who is asking rather than by topic, because a buyer and a seller
// want different halves of the same answer and neither wants to read the
// other's. The numbers come from lib/legal so this page cannot drift away
// from the Terms or from what the software actually does.

type Question = { q: string; a: React.ReactNode };

const SECTIONS: { title: string; questions: Question[] }[] = [
  {
    title: 'Buying',
    questions: [
      {
        q: 'How do I know I will not be ripped off?',
        a: (
          <>
            <p>
              Your money goes to Restrum, not to the seller, and it stays with
              us until you confirm the gear arrived and is what the listing
              said. If it never arrives, or it is materially not as described,
              we refund you. The seller cannot touch the money in the meantime.
            </p>
            <p>
              That is the whole reason the site takes payments at all. A
              marketplace that just introduces you to a stranger and steps back
              is not offering you anything you could not get for free.
            </p>
          </>
        ),
      },
      {
        q: 'What happens after I pay?',
        a: (
          <p>
            The listing comes off the market, the seller is told to send it,
            and the order appears under Your orders with its current state. When
            it arrives, check it properly, then press confirm. That releases the
            payment. If you never get round to it, the money releases
            automatically after {AUTO_RELEASE_DAYS} days, so a seller is not
            left waiting forever on someone who has stopped replying.
          </p>
        ),
      },
      {
        q: 'It arrived and it is not right. Now what?',
        a: (
          <>
            <p>
              Do not confirm receipt. Message the seller first, since most
              problems are a misunderstanding about postage or condition and get
              sorted in a couple of messages. If that goes nowhere, email{' '}
              {OPERATOR.contactEmail} with your order number before the{' '}
              {AUTO_RELEASE_DAYS} days are up. The money stays put while we look
              at it.
            </p>
            <p>
              Please come to us before raising a chargeback with your bank. A
              chargeback takes the decision out of everyone's hands and is
              slower than a refund we make ourselves.
            </p>
          </>
        ),
      },
      {
        q: 'Can I return something just because I changed my mind?',
        a: (
          <p>
            Usually not. Most sellers here are private individuals, and buying
            privately does not carry the 14 day cooling off period you get from
            a shop. What you are entitled to is an item that matches its
            description and a seller who actually owns it. If someone is selling
            as a business they have to say so, and then your normal consumer
            rights apply against them in full. Clause 7 of the{' '}
            <Link to="/terms">Terms</Link> sets this out properly.
          </p>
        ),
      },
      {
        q: 'Why did it say someone else is checking out?',
        a: (
          <p>
            Because they are. When a buyer starts paying, the listing is held
            for them for {RESERVATION_MINUTES} minutes so that two people cannot
            pay for the same guitar. If they do not finish, it goes straight
            back on sale. There is only one of most things here, which is rather
            the point.
          </p>
        ),
      },
      {
        q: 'I made an offer and the seller accepted. Have I bought it?',
        a: (
          <>
            <p>
              Not yet. Accepting fixes the price for you, it does not take the
              item off the market. You have {OFFER_HOURS} hours to pay at the
              agreed figure, and the Pay button appears in the conversation and
              on the listing itself.
            </p>
            <p>
              Until you pay, anyone else can still buy it at the asking price,
              and whoever pays first gets it. That cuts both ways and it is
              meant to: a seller who says yes to an offer should not lose the
              sale to someone who then goes quiet for a week. Postage is not
              part of the haggle, since the seller is recovering what the
              courier charges rather than making a margin on it.
            </p>
          </>
        ),
      },
      {
        q: 'How do I arrange delivery or collection?',
        a: (
          <p>
            Between you and the seller, in the messages on the order. Restrum
            does not ship anything and does not sell postage. Agree it before
            you pay if it matters, especially on anything heavy: a 4x12 cabinet
            is not a parcel.
          </p>
        ),
      },
    ],
  },
  {
    title: 'Selling',
    questions: [
      {
        q: 'What does it cost to sell?',
        a: (
          <p>
            {PLATFORM_FEE_PERCENT}% of the sale price, taken only when
            something actually sells. No listing fee, no monthly fee, nothing
            for photographs or audio clips, nothing to appear in search. Card
            processing charges come out of our {PLATFORM_FEE_PERCENT}%, not out
            of what you are owed.
          </p>
        ),
      },
      {
        q: 'When do I get paid?',
        a: (
          <p>
            When the buyer confirms the gear arrived, or automatically{' '}
            {AUTO_RELEASE_DAYS} days after payment if they never get round to
            it. It goes to your Stripe account and then to your bank on Stripe's
            timetable. You will see exactly what you are owed, after the fee, on
            the order page from the moment it is paid.
          </p>
        ),
      },
      {
        q: 'Why do I have to set up Stripe before anyone can buy?',
        a: (
          <>
            <p>
              Because otherwise we would be taking someone's money for an item
              whose seller has no way to receive it. Stripe verifies who you are
              and where to pay you. It takes a few minutes and happens on
              Stripe's own pages: Restrum never sees your bank details.
            </p>
            <p>
              You do not need a company or a VAT number. Selling as an
              individual is fine.
            </p>
          </>
        ),
      },
      {
        q: 'How much detail should I put in a listing?',
        a: (
          <>
            <p>
              More than feels necessary. The filters only work on what sellers
              fill in, so a listing that states its pickup configuration, scale
              length and era turns up in far more of the right searches than one
              that says "guitar, good condition".
            </p>
            <p>
              Be honest about faults. A described fault costs you a little on
              the price; an undescribed one costs you the whole sale when the
              buyer opens a dispute, and they will win it.
            </p>
          </>
        ),
      },
      {
        q: 'What is gear history and is it worth filling in?',
        a: (
          <p>
            It is a log attached to the instrument: original purchase,
            services, repairs, modifications. It is the single biggest thing
            separating a listing that reads as trustworthy from one that reads
            as a punt. A refret honestly logged adds confidence; a refret
            discovered later loses a sale.
          </p>
        ),
      },
      {
        q: 'Do I have to pay tax on this?',
        a: (
          <p>
            Selling a few things you no longer use is not usually trading and
            not usually taxable. Buying to resell generally is. We cannot draw
            that line for you, and we are required to report seller information
            to HMRC each year under the digital platform reporting rules, which
            clause 10 of the <Link to="/terms">Terms</Link> covers.
          </p>
        ),
      },
    ],
  },
  {
    title: 'The site itself',
    questions: [
      {
        q: 'What is the gear adviser?',
        a: (
          <p>
            The chat button in the corner. Tell it what you play and what you
            have to spend and it searches the actual listings and points at
            specific ones. It can only recommend things that are genuinely for
            sale right now, and it will tell you when there is nothing good on.
            It is AI, so check the listing before you buy anything on its say
            so.
          </p>
        ),
      },
      {
        q: 'What does the verified tick next to a seller mean?',
        a: (
          <p>
            That other people on Restrum who have actually dealt with them have
            vouched for them. It is not a badge anyone can buy or award
            themselves, and it takes more than one person. A new seller without
            one is not a warning sign, it just means nobody has traded with them
            here yet.
          </p>
        ),
      },
      {
        q: 'Where does the price guidance come from?',
        a: (
          <p>
            From other listings on the site in the same corner of the catalogue,
            plus a small reference table for common models. It is a rough
            steer from limited data, not a valuation, and the tooltip on the
            listing says exactly how many listings it was calculated from so you
            can judge how much to trust it.
          </p>
        ),
      },
      {
        q: 'Is my data safe?',
        a: (
          <p>
            Card and bank details never reach our servers at all, which is the
            most useful thing we can tell you about how they are protected.
            Passwords are stored as hashes. The full detail, including what we
            keep and for how long, is in the{' '}
            <Link to="/privacy">Privacy Policy</Link>.
          </p>
        ),
      },
      {
        q: 'Something is broken, or I have a question this does not answer.',
        a: (
          <p>
            Email {OPERATOR.contactEmail}. A real person reads it and we aim to
            reply within five working days, sooner if money is involved.
          </p>
        ),
      },
    ],
  },
];

function FaqItem({ question }: { question: Question }) {
  const [open, setOpen] = useState(false);

  return (
    <div className={`faq-item${open ? ' faq-item--open' : ''}`}>
      <button className="faq-item__question" onClick={() => setOpen(!open)} aria-expanded={open}>
        <span>{question.q}</span>
        <span className="faq-item__marker" aria-hidden="true" />
      </button>

      {/* Rendered only when open rather than hidden with CSS: an accordion
          that keeps every answer in the DOM puts the whole page into a
          find-on-page search, which is maddening on a page of questions. */}
      {open && <div className="faq-item__answer">{question.a}</div>}
    </div>
  );
}

function Faq() {
  return (
    <div className="faq">
      <header className="faq__head">
        <p className="about__eyebrow">Help</p>
        <h1 className="faq__title">Questions, answered</h1>
        <p className="faq__lede">
          How buying, selling and getting paid actually work. If something here
          is not clear, that is worth telling us about:{' '}
          {OPERATOR.contactEmail}.
        </p>
      </header>

      {SECTIONS.map(section => (
        <section className="faq__section" key={section.title}>
          <h2 className="faq__section-title">{section.title}</h2>
          <div className="faq__list">
            {section.questions.map(question => (
              <FaqItem key={question.q} question={question} />
            ))}
          </div>
        </section>
      ))}

      <p className="faq__footer">
        Still stuck? <Link to="/about">Read more about what Restrum is</Link>,
        or email {OPERATOR.contactEmail}.
      </p>
    </div>
  );
}

export default Faq;
