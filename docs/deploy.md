# Deploying Restrum

The runbook for putting restrum.uk on a Hetzner box behind Cloudflare.

Everything below runs **on the server over SSH** unless a step says
otherwise. The commands are bash; your local shell is PowerShell, so do not
paste them into it.

## The shape of it

One small server runs the whole stack under Docker Compose: nginx, PHP-FPM,
a queue worker, a scheduler, Reverb, MySQL, Redis and Elasticsearch. Nothing
but ports 80 and 443 is reachable, and those only from Cloudflare.

Cloudflare sits in front as the public face. It terminates TLS for the
browser, and this origin terminates TLS again for Cloudflare using an origin
certificate that only Cloudflare trusts. That arrangement is what makes the
origin useless to anyone who finds its IP, and it means no certificate
renewal on a schedule.

The site goes up running the complete payment system against **test** Stripe
keys. The whole flow works end to end and moves no real money, so switching
to live keys later is this configuration changing, not a rebuild. Stripe
account verification never blocks the deploy.

## Before you start

- The domain, already on Cloudflare Registrar. Nothing to buy.
- A Hetzner account.
- An SSH key pair. If you do not have one, on Windows:
  `ssh-keygen -t ed25519`, then the public half is in
  `$env:USERPROFILE\.ssh\id_ed25519.pub`.
- Your Stripe **test** keys to hand.

---

## 1. Create the server

In the Hetzner Cloud console: new project, then new server.

- **Location:** Falkenstein or Nuremberg. Both are fine for UK traffic once
  Cloudflare is in front, and they are the cheapest.
- **Image:** Ubuntu 24.04.
- **Type:** Shared vCPU, x86, **CX22**. Two vCPU and 4GB, which is enough
  for this stack with the Elasticsearch heap pinned to 512MB as the compose
  file does. Check the current price in the console rather than trusting a
  figure from me; it is a few euros a month and billed hourly, so destroying
  the server stops the bill the same day.
- **SSH key:** paste your public key here. Do not choose password login.
- Leave backups off for now. Section "Backups" below is the cheaper answer
  for a database this size.

Note the IPv4 address it gives you. Then:

```bash
ssh root@YOUR_SERVER_IP
```

## 2. Prepare the machine

Updates, a working user, and swap. Swap matters more than it looks: 4GB is
comfortable for running this stack but tight while Docker is compiling the
React bundle, and the build is where an out-of-memory kill would land.

```bash
apt update && apt upgrade -y

adduser --disabled-password --gecos "" lewis
usermod -aG sudo lewis
rsync --archive --chown=lewis:lewis ~/.ssh /home/lewis

fallocate -l 2G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

Then SSH restrictions and a basic firewall. Note what ufw can and cannot do
here: it governs traffic to the host, so it covers SSH, but it does **not**
govern traffic to containers. Ports 80 and 443 are handled in step 7.

```bash
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart ssh

ufw allow OpenSSH
ufw --force enable
```

Open a second terminal and confirm `ssh lewis@YOUR_SERVER_IP` works
**before** closing the root session. Locking yourself out here means
rebuilding the server.

## 3. Install Docker

```bash
curl -fsSL https://get.docker.com | sh
usermod -aG docker lewis
```

Log out and back in as `lewis` so the group membership takes effect, then
check it: `docker run --rm hello-world`.

## 4. Point the domain at it

In the Cloudflare dashboard, on the restrum.uk zone, under DNS:

| Type | Name | Content | Proxy |
|------|------|---------|-------|
| A | `restrum.uk` | your server IPv4 | **Proxied** (orange cloud) |
| A | `www` | your server IPv4 | **Proxied** (orange cloud) |

The orange cloud is the whole design. Grey-clouded, the origin certificate
below will not validate in a browser and the real-IP handling stops making
sense.

Then under **SSL/TLS → Overview**, set the mode to **Full (strict)**.
Flexible would let Cloudflare talk to the origin in plaintext, which defeats
the point of the certificate.

## 5. The origin certificate

Under **SSL/TLS → Origin Server**, create an origin certificate. Accept the
defaults: it covers `restrum.uk` and `*.restrum.uk` and lasts 15 years.

Cloudflare shows you the certificate and the private key **once**. Copy both
onto the server:

```bash
mkdir -p ~/restrum/certs
nano ~/restrum/certs/restrum.uk.pem   # paste the certificate
nano ~/restrum/certs/restrum.uk.key   # paste the private key
chmod 600 ~/restrum/certs/restrum.uk.key
```

The paths matter: `docker-compose.prod.yml` mounts `./certs` and
`docker/nginx.prod.conf` expects exactly these two filenames.

## 6. Get the code and configure it

```bash
cd ~/restrum
git clone YOUR_REPO_URL .
cp .env.production.example .env.production
```

Generate the secrets. Keep them alphanumeric: compose runs this file through
variable interpolation, so a literal `$` in a password has to be escaped as
`$$` and is a reliable way to end up with a password that differs between
the server and the client.

```bash
openssl rand -hex 24   # DB_PASSWORD
openssl rand -hex 24   # DB_ROOT_PASSWORD
openssl rand -hex 16   # REVERB_APP_ID
openssl rand -hex 16   # REVERB_APP_KEY
openssl rand -hex 24   # REVERB_APP_SECRET
```

Edit `.env.production` and fill those in, plus your Stripe test keys. Leave
`STRIPE_WEBHOOK_SECRET` blank for now; step 10 produces it. Leave
`ANTHROPIC_API_KEY` blank unless you have decided to run the gear adviser:
with it blank the widget does not render at all and costs nothing.

`APP_KEY` needs the app image, so it comes after the first build. Leave it
blank and come back to it in step 8.

Read the comments at the top of the file before you edit it. In particular,
do not set `DB_HOST`, `REDIS_HOST` or the Reverb host here: the compose file
sets those because they describe the container network, and it will override
anything you write.

## 7. Lock 80 and 443 to Cloudflare

```bash
chmod +x ~/restrum/docker/*.sh
sudo ~/restrum/docker/firewall-cloudflare.sh
```

This writes rules into the DOCKER-USER chain, because ufw genuinely cannot
do this job: Docker publishes ports by writing its own iptables rules ahead
of anything ufw manages. Without this step the origin answers the whole
internet, and since nginx is configured to believe Cloudflare's
`CF-Connecting-IP` header, anyone could forge it, which among other things
resets the gear adviser's per-IP rate limit on every request.

Those rules do not survive a reboot on their own, so have systemd reapply
them after Docker starts:

```bash
sudo tee /etc/systemd/system/restrum-firewall.service >/dev/null <<'UNIT'
[Unit]
Description=Restrict published ports to Cloudflare
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
ExecStart=/home/lewis/restrum/docker/firewall-cloudflare.sh
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
UNIT

sudo systemctl enable --now restrum-firewall.service
```

## 8. First deploy

```bash
cd ~/restrum
./docker/deploy.sh
```

The first build takes a while: it compiles the React bundle and resolves
Composer dependencies on two cores. Later deploys reuse the layers.

It will stop and tell you `APP_KEY` is missing. Generate one now that the
image exists, put it in `.env.production`, and run the script again:

```bash
docker compose --env-file .env.production -f docker-compose.prod.yml \
    run --rm --no-deps app php artisan key:generate --show
```

Migrations run automatically, in the `app` container's entrypoint only, so
the four containers sharing that image do not race each other on the schema.

That compose incantation gets old fast. Worth adding to `~/.bashrc`:

```bash
echo "alias dc='docker compose --env-file ~/restrum/.env.production -f ~/restrum/docker-compose.prod.yml'" >> ~/.bashrc
```

## 9. Fill the catalogue and check it

The taxonomy lives in code and has to be pushed into the database once, and
again whenever `app/Catalog/Taxonomy.php` changes:

```bash
dc exec app php artisan catalog:sync
```

Then check the things that are easy to get wrong and silent when they are:

```bash
# The app answers at all.
curl -sS https://restrum.uk/up

# The real client IP is getting through, not a Cloudflare address. The last
# field of the access log line should be your own IP.
dc logs --tail=5 nginx

# The scheduler is alive. Within five minutes this should mention
# orders:sweep.
dc logs --tail=20 scheduler

# Every container is up rather than restarting in a loop.
dc ps
```

Then in a browser: load the site, register an account, create a listing with
a photo, and open a conversation from a second account to confirm the
websocket works. If messages only appear on reload, Reverb is not being
reached; see Troubleshooting.

## 10. Register the Stripe webhook

In the Stripe dashboard, with **test mode** on, add an endpoint at:

```
https://restrum.uk/api/stripe/webhook
```

Select these five events, and only these. The app ignores anything else, and
sending it everything only makes the queue busier:

| Event | What it does here |
|-------|-------------------|
| `payment_intent.succeeded` | Records the payment, marks the listing sold. **Without this nothing is ever paid.** |
| `payment_intent.canceled` | Releases a listing whose payment was cancelled. |
| `charge.refunded` | Records refunds, including ones issued by hand in the dashboard. |
| `charge.dispute.created` | Freezes an order so a chargeback does not auto-release to the seller. |
| `account.updated` | Tracks whether a seller has finished Connect onboarding. |

Note there is no `payment_intent.payment_failed`. A declined card leaves the
payment retryable and the buyer can try another one against the same order,
so there is nothing to do; what ends an abandoned attempt is the reservation
lapsing, which the scheduler handles.

Copy the endpoint's signing secret into `STRIPE_WEBHOOK_SECRET` in
`.env.production`, then `./docker/deploy.sh` to pick it up. This is not the
same value as the one `stripe listen` prints locally.

Until that secret is set the app rejects every webhook, which is correct
rather than broken: an unverified webhook is an open endpoint that moves
money on request. It does mean payments will not complete before you do
this.

Test a payment end to end with card `4242 4242 4242 4242`, any future
expiry, any CVC.

---

## Redeploying

```bash
cd ~/restrum && git pull && ./docker/deploy.sh
```

Config-only changes, meaning anything in `.env.production` or the mounted
nginx files, do not need a rebuild:

```bash
dc up -d
```

One exception worth remembering: `REVERB_APP_KEY` is baked into the
JavaScript bundle at build time, so changing it **does** require a rebuild.

## Backups

The database and the uploaded photos are the only things here that cannot be
rebuilt from the repo. A nightly dump, kept for a fortnight:

```bash
mkdir -p ~/backups
crontab -e
```

```cron
0 3 * * * cd ~/restrum && docker compose --env-file .env.production -f docker-compose.prod.yml exec -T mysql mysqldump -u root -p"$(grep '^DB_ROOT_PASSWORD=' .env.production | cut -d= -f2)" restrum | gzip > ~/backups/restrum-$(date +\%F).sql.gz && find ~/backups -name '*.sql.gz' -mtime +14 -delete
```

That leaves the backups on the same disk as the thing they protect, which
guards against a bad migration but not against losing the server. Copy them
off periodically, or turn on Hetzner's snapshots once there is data worth
the extra euro.

Uploaded photos live in the `storage-data` volume. They survive restarts and
rebuilds, but not the server being destroyed, and nothing backs them up
until you either add them to the cron above or move `MEDIA_DISK` to object
storage.

## Troubleshooting

**Cloudflare error 521 or 522.** Cloudflare cannot reach the origin. Check
`dc ps` shows nginx up, then check the firewall rules did not outlive a
change of Cloudflare ranges: `sudo iptables -L DOCKER-USER -n`.

**Cloudflare error 526.** Full (strict) is on but the origin certificate is
missing, malformed, or not the one Cloudflare issued. Check both files exist
in `~/restrum/certs` and that the `.pem` includes the whole block including
the BEGIN and END lines.

**Messages need a page reload to appear.** The websocket is not connecting.
Check `dc logs reverb`, and check the browser console for a failed
connection to `wss://restrum.uk/app/...`. The usual cause is
`REVERB_APP_KEY` changing without a rebuild, since the browser's copy is
baked into the bundle.

**Everything is slow, or a container keeps restarting.** Check memory with
`free -h` and `docker stats`. Elasticsearch is the usual culprit; its heap
is pinned at 512MB in the compose file and should not be raised on a CX22.

**The access log shows Cloudflare addresses instead of visitors.** The
`cloudflare-ips.conf` mount is not taking effect. Confirm with
`dc exec nginx ls /etc/nginx/conf.d/`, which should list it alongside
`default.conf`.

**Refreshing the Cloudflare ranges.** Rarely needed. Compare
https://www.cloudflare.com/ips-v4 and `/ips-v6` against the lists in
`docker/cloudflare-ips.conf` and `docker/firewall-cloudflare.sh`, update
both, then `dc restart nginx` and re-run the firewall script.

## Later: going live with real money

Nothing structural changes. Finish Stripe account verification, swap the
test keys in `.env.production` for live ones, register a webhook endpoint in
live mode and update the signing secret, then `dc up -d`.

Before that switch, the things that stop being optional: `MAIL_MAILER` needs
to point at a real provider rather than discarding mail, since password
resets and order notifications matter once there are real accounts, and the
`legalName` and `postalAddress` placeholders in `frontend/src/lib/legal.ts`
have to be filled in, since a sole trader has to publish their own name and
a service address on the terms and privacy pages.
