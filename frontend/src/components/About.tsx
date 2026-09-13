import { Link } from 'react-router-dom';

import { PLATFORM_FEE_PERCENT, AUTO_RELEASE_DAYS } from '../lib/legal';

// The About page.
//
// Written as an argument rather than a brochure: what is wrong with buying
// used gear, and what this site does about each part of it. Every claim here
// maps to something the software actually does, which is the only way this
// page stays true as the product changes.

function Pillar({ n, title, children }: { n: string; title: string; children: React.ReactNode }) {
  return (
    <div className="pillar">
      <span className="pillar__number">{n}</span>
      <h3 className="pillar__title">{title}</h3>
      <div className="pillar__body">{children}</div>
    </div>
  );
}

function About() {
  return (
    <div className="about">
      <header className="about__hero">
        <p className="about__eyebrow">About Restrum</p>
        <h1 className="about__title">
          Every instrument has been somewhere.{' '}
          <span className="about__title-accent">You should know where.</span>
        </h1>
        <p className="about__lede">
          Restrum is a UK marketplace for secondhand instruments, hi-fi, records
          and the enormous pile of cables and stands that comes with them. It
          exists because buying used gear online is worse than it needs to be,
          and almost all of that is fixable.
        </p>
      </header>

      <section className="about__section">
        <h2 className="about__heading">The problem</h2>
        <p>
          A used guitar is not a used phone. Two Telecasters built in the same
          week, sold for the same money, can be completely different
          instruments: one has been gigged for fifteen years and refretted
          twice, the other sat in a case under a bed. The story is the product.
          Most of the places you can buy one have no room to tell it.
        </p>
        <p>
          So you end up choosing between bad options. General classifieds are
          cheap and fast and put you in a car park with a stranger and an
          envelope of cash, with no recourse when the amp turns out not to
          power on. Auction sites protect the payment but bury the gear in
          categories built for something else, where a vintage ribbon
          microphone and a phone case are both "electronics". Specialist
          dealers know exactly what they are selling and take a margin that
          reflects it.
        </p>
        <p>
          None of those are wrong, exactly. They are just built for general
          goods, or for trade. Nobody built the one for people who actually
          play.
        </p>
      </section>

      <section className="about__section">
        <h2 className="about__heading">What we do about it</h2>

        <div className="pillars">
          <Pillar n="01" title="Your money is held, not handed over">
            <p>
              You pay Restrum, not the seller. We hold the money while the gear
              is in transit and release it only once you have it in your hands
              and confirm it is what was described. If it never turns up, or it
              is not the thing in the photographs, you have not lost anything.
            </p>
            <p>
              Sellers get paid into their own verified account, automatically,
              once you confirm or after {AUTO_RELEASE_DAYS} days if you go
              quiet. Nobody is waiting on a bank transfer from someone they
              have never met.
            </p>
          </Pillar>

          <Pillar n="02" title="Gear history, kept with the gear">
            <p>
              Every listing can carry a history: when it was bought, what has
              been serviced, what has been modified, what broke and who fixed
              it. Not a description a seller writes once, but a log that stays
              with the instrument.
            </p>
            <p>
              A refret is not a flaw. A replaced pickup is not a flaw. Not
              knowing is the flaw.
            </p>
          </Pillar>

          <Pillar n="03" title="Filters built by someone who plays">
            <p>
              You can narrow to a seven string with humbuckers, a belt drive
              deck with a working phono stage, a 45 RPM seven inch graded VG+
              or better, or a jack to jack lead long enough to reach the back
              of the stage. Records are graded on the Goldmine scale that every
              record shop already uses.
            </p>
            <p>
              Six hundred categories, because "musical instruments" is not a
              category, it is a shrug.
            </p>
          </Pillar>

          <Pillar n="04" title="Sellers vouched for by people, not badges">
            <p>
              Verification here is not a tick you buy or a form you fill in. It
              comes from other people on the site who have actually dealt with
              that seller, and it only counts once they have genuinely traded.
            </p>
            <p>
              A reputation you cannot award yourself is the only kind worth
              displaying.
            </p>
          </Pillar>

          <Pillar n="05" title="Honest price context">
            <p>
              Every listing shows how its price sits against comparable gear on
              the site, and a rough reference for what the model usually goes
              for. It is an estimate from limited data and we say so on the
              page rather than dressing it up as a valuation.
            </p>
          </Pillar>

          <Pillar n="06" title="One fee, and we pay the card charges">
            <p>
              Buyers pay the listed price. Sellers pay{' '}
              {PLATFORM_FEE_PERCENT}% on a completed sale and nothing else: no
              listing fee, no monthly fee, no charge for photographs or for
              appearing in search. Card processing comes out of our side, not
              yours.
            </p>
            <p>
              The fee that applies is the one in force when the buyer paid, so
              changing it later never rewrites a sale that already happened.
            </p>
          </Pillar>
        </div>
      </section>

      <section className="about__section">
        <h2 className="about__heading">What we are not</h2>
        <p>
          We are not a shop. We do not own, store, inspect or ship any of the
          gear here, and we are not the seller on any listing. What we are is
          the thing standing between two strangers making the exchange
          survivable: we hold the money, we keep the record, and we give the
          honest sellers somewhere their honesty is worth something.
        </p>
        <p>
          We are also not pretending the community is bigger than it is.
          Restrum is new. If you are reading this early, you are early, and the
          listings you put up are the reason the next person finds anything
          here at all.
        </p>
      </section>

      <section className="about__section about__section--cta">
        <h2 className="about__heading">Start somewhere</h2>
        <p>
          Have a look at what is on, or put up the thing that has been in its
          case since the last band ended.
        </p>
        <div className="about__actions">
          <Link className="btn-primary btn-lg" to="/">Browse the gear</Link>
          <Link className="btn-ghost btn-lg" to="/create">Sell something</Link>
        </div>
        <p className="about__footnote">
          Questions about how any of it works are answered on the{' '}
          <Link to="/faq">FAQ</Link>. The rules are in the{' '}
          <Link to="/terms">Terms</Link>, and what we do with your data is in
          the <Link to="/privacy">Privacy Policy</Link>.
        </p>
      </section>
    </div>
  );
}

export default About;
