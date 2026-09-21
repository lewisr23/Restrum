<?php

namespace App\Services\Safety;

/**
 * Spots a message steering the other person off Restrum to pay.
 *
 * This is the scam the whole escrow design exists to stop, attempted from
 * the one place escrow cannot reach. "Bank transfer is easier, I'll knock
 * GBP 50 off" takes the buyer outside every protection on the site in one
 * sentence, and the seller's Stripe identity check stops mattering the
 * moment the money never goes through Stripe.
 *
 * Deliberately a warning rather than a block. Three reasons, in order:
 *
 *  - Plenty of hits are honest. A seller offering their number so a buyer
 *    can arrange collection is not running a scam, and refusing to send
 *    their message would make the site feel broken.
 *  - A block teaches evasion. Tell someone "bank transfer" is banned and
 *    the next message says "b a n k". A warning the recipient can see keeps
 *    working against wording nobody anticipated, because it is the recipient
 *    who has to be talked out of paying.
 *  - The person who needs protecting is the one reading, not the one typing.
 *
 * What this cannot do, and should not be trusted to: catch a careful
 * scammer. Someone who writes "send it the usual way, you know the one"
 * sails straight through. It raises the cost of the lazy version, which is
 * the version almost everyone runs.
 */
class OffPlatformScanner
{
    /**
     * Patterns worth interrupting a sale over, and worth an admin's time.
     *
     * Either bank details in the clear, a payment rail with no recall at
     * all, or someone naming the fee as the reason to go elsewhere. None of
     * these has an innocent reading on a marketplace that takes payment
     * itself.
     *
     * @var array<string, string>
     */
    private const SEVERE = [
        'bank_details' => '/\b(sort ?code|account number|acc(ount)? no\b|iban)\b/i',

        'iban_digits' => '/\bGB\d{2}\s?[A-Z]{4}\s?[\d\s]{14,20}\b/i',

        'bank_transfer' => '/\b(bank transfer|bank trans|direct transfer|wire transfer|transfer (it |the money )?(straight|direct)(ly)? (to|into))\b/i',

        // PayPal's "friends and family" option removes buyer protection.
        // Asking for it is asking to be unrecoverable.
        'friends_and_family' => '/\b(friends? (and|&) family|f\s?&\s?f\b|as a gift)\b/i',

        'untraceable_rail' => '/\b(western union|moneygram|money gram|gift ?card|steam card|amazon card|voucher code|bitcoin|btc\b|ethereum|usdt|crypto)\b/i',

        'dodging_fees' => '/\b(avoid(ing)? the fees?|without the fees?|save (on )?the fees?|no fees?( if| when)|off the (app|site|platform)|outside (the )?(app|site|platform|restrum)|off[- ]platform|cut out the middle ?man)\b/i',
    ];

    /**
     * Patterns worth a word of caution and nothing more.
     *
     * Swapping a number or naming another payment app is the usual first
     * step off the platform, and is also completely ordinary behaviour
     * between two people arranging a collection. The reader gets told; no
     * moderator gets woken up.
     *
     * @var array<string, string>
     */
    private const NOTABLE = [
        'other_payment_app' => '/\b(paypal|pay ?pal|revolut|monzo|starling|venmo|cash ?app|zelle|wise\.com|transferwise)\b/i',

        'other_channel' => '/\b(whats ?app|telegram|kik\b|snapchat|signal (me|app)|text me on|message me on)\b/i',

        // A UK mobile or landline, however it is spaced out.
        'phone_number' => '/(?<!\d)(\+44\s?7\d{3}|\(?07\d{3}\)?|\+44\s?1\d{3}|\(?01\d{3}\)?)[\s-]?\d{3}[\s-]?\d{3}(?!\d)/',

        'email_address' => '/[\w.+-]+@[\w-]+\.[\w.-]{2,}/i',

        // Six digits in two-digit groups. On its own this is only worth a
        // caution, because on a music gear site it is at least as likely to
        // be a serial number off the back of an amp as a sort code. Paired
        // with an eight digit run it stops being ambiguous - see the severe
        // bank_account_digits check below.
        'sort_code_digits' => '/(?<!\d)\d{2}[-\s]\d{2}[-\s]\d{2}(?!\d)/',
    ];

    /** An account number: eight digits together, and nothing else. */
    private const ACCOUNT_DIGITS = '/(?<!\d)\d{8}(?!\d)/';

    /**
     * @return array<int, string> The names of every signal that matched,
     *                            severe ones first. Empty means clean.
     */
    public function scan(string $content): array
    {
        // Collapsed whitespace so "sort  code" and a line break in the
        // middle of a phrase read the same as the plain version. This is
        // not obfuscation-proof and is not meant to be: see the class note.
        $text = preg_replace('/\s+/u', ' ', $content) ?? $content;

        $hits = [];

        foreach (self::SEVERE as $name => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $hits[] = $name;
            }
        }

        foreach (self::NOTABLE as $name => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $hits[] = $name;
            }
        }

        // A sort code and an account number in the same message is somebody
        // handing over their bank details, whatever words are around it.
        // Neither half is conclusive alone: the first reads like a serial
        // number, the second like a year of manufacture and a price.
        if (in_array('sort_code_digits', $hits, true)
            && preg_match(self::ACCOUNT_DIGITS, $text) === 1) {
            array_unshift($hits, 'bank_account_digits');
        }

        return $hits;
    }

    /** Whether any of these hits is one a moderator should see. */
    public function isSevere(array $hits): bool
    {
        $severe = array_keys(self::SEVERE);
        $severe[] = 'bank_account_digits';

        return array_intersect($hits, $severe) !== [];
    }
}
