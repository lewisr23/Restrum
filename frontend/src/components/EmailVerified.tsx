import { useEffect } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

/**
 * Where the link in the verification email lands.
 *
 * The API does the verifying and redirects here with ?status, because a
 * link opened in a mail client needs a page at the end of it rather than a
 * JSON body. Nothing is posted from this page: by the time it renders the
 * work is already done.
 */
function EmailVerified() {
  const [params] = useSearchParams();
  const { user, updateUser } = useAuth();

  const status = params.get('status') ?? 'invalid';

  // The account may well be signed in on this browser already, in which case
  // the stored copy still says unverified and the banner would keep nagging
  // about something that just succeeded.
  useEffect(() => {
    if ((status === 'verified' || status === 'already') && user && !user.email_verified_at) {
      updateUser({ email_verified_at: new Date().toISOString() });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status]);

  const content = {
    verified: {
      title: 'Address confirmed',
      body: 'That is you sorted. You can list gear, buy, and message sellers.',
    },
    already: {
      title: 'Already confirmed',
      body: 'This address was confirmed before, so there is nothing left to do.',
    },
    invalid: {
      title: 'That link did not work',
      body:
        'Verification links run out after an hour, and they stop working if the ' +
        'address on the account changes. Log in and ask for a new one from the ' +
        'banner at the top of the page.',
    },
  }[status] ?? {
    title: 'That link did not work',
    body: 'Log in and ask for a new one from the banner at the top of the page.',
  };

  return (
    <div className="auth-card">
      <h1 className="auth-card__title">{content.title}</h1>
      <p className="text-muted">{content.body}</p>

      <p className="auth-card__footer">
        <Link className="auth-card__link" to={user ? '/' : '/login'}>
          {user ? 'Start browsing' : 'Log in'}
        </Link>
      </p>
    </div>
  );
}

export default EmailVerified;
