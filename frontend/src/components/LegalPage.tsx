import { ReactNode } from 'react';

import { OPERATOR, missingOperatorDetails } from '../lib/legal';

// Shared shell for the Terms and the Privacy Policy: the same title block,
// the same last-updated line, the same measure. Only the prose differs, so
// only the prose lives in the two page components.
function LegalPage({ title, intro, children }: { title: string; intro: string; children: ReactNode }) {
  const missing = missingOperatorDetails();

  return (
    <div className="legal">
      <h1 className="legal__title">{title}</h1>
      <p className="legal__updated">Last updated {OPERATOR.lastUpdated}</p>

      {/* Deliberately loud, and deliberately on the page rather than in a
          comment. These documents are not usable until the operator's legal
          name and service address are real, and a warning nobody can miss is
          the only version of this reminder that works. */}
      {missing.length > 0 && (
        <div className="notice notice--error">
          <strong>Draft, not ready to publish.</strong> These details are still
          unset in <code>src/lib/legal.ts</code>: {missing.join(', ')}. A UK
          trading name on its own does not tell a customer who they are
          contracting with, and both the Companies Act 2006 and the Ecommerce
          Regulations 2002 require more than one.
        </div>
      )}

      <p className="legal__intro">{intro}</p>

      <div className="legal__body">{children}</div>
    </div>
  );
}

/** A numbered top-level clause. */
export function Clause({ n, title, children }: { n: number; title: string; children: ReactNode }) {
  return (
    <section className="legal__clause">
      <h2 className="legal__clause-title">
        <span className="legal__clause-number">{n}.</span> {title}
      </h2>
      {children}
    </section>
  );
}

export default LegalPage;
