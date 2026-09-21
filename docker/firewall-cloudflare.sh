#!/usr/bin/env bash
#
# Restrict ports 80 and 443 on this host to Cloudflare's edge.
#
# Two reasons this is not optional decoration:
#
#   1. ufw does not police container traffic. Docker writes its own iptables
#      rules in the FORWARD path, ahead of anything ufw manages, so a
#      published port is reachable from the internet no matter what ufw
#      says. Rules for containers belong in the DOCKER-USER chain, which
#      Docker creates for exactly this purpose and never overwrites.
#
#   2. docker/cloudflare-ips.conf tells nginx to believe the CF-Connecting-IP
#      header. That is only sound while Cloudflare is the only thing that
#      can reach the origin. Leave 443 open to the world and anyone can set
#      that header to a fresh value per request, which among other things
#      resets the gear adviser's per-IP rate limit - the one that stops a
#      public endpoint running up an Anthropic bill.
#
# Idempotent: it flushes its own rules before adding them, so running it
# twice is the same as running it once. Run it after every Docker restart,
# which is what the systemd unit in docs/deploy.md arranges.
#
# Refresh the ranges when Cloudflare changes them (rarely) by re-running
# with the lists re-fetched; see docs/deploy.md.

set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Needs root." >&2
    exit 1
fi

PORTS="80,443"

# Cloudflare's published ranges, matching docker/cloudflare-ips.conf.
# Fetched 2026-09-14 from https://www.cloudflare.com/ips-v4 and /ips-v6.
V4="173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22
    141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20
    197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13
    104.24.0.0/14 172.64.0.0/13 131.0.72.0/22"

V6="2400:cb00::/32 2606:4700::/32 2803:f800::/32 2405:b500::/32
    2405:8100::/32 2a06:98c0::/29 2c0f:f248::/32"

# Everything this script adds carries this comment, so it can find and
# remove its own previous rules without touching Docker's.
TAG="restrum-cloudflare"

# The interface the internet arrives on. Every rule below is scoped to it,
# and that scoping is load-bearing rather than tidiness: DOCKER-USER sees
# FORWARDed packets in BOTH directions, so an unscoped "drop tcp dport 443"
# also drops a container opening an outbound HTTPS connection. That breaks
# the image build (apk cannot reach its mirror) and, far worse, silently
# breaks every call the app makes to api.stripe.com.
EXT_IF=$(ip route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "dev") print $(i + 1); exit}')

if [ -z "$EXT_IF" ]; then
    echo "Could not determine the external interface." >&2
    exit 1
fi

flush() {
    local cmd=$1
    while $cmd -L DOCKER-USER --line-numbers -n 2>/dev/null | grep -q "$TAG"; do
        local line
        line=$($cmd -L DOCKER-USER --line-numbers -n | grep "$TAG" | head -1 | awk '{print $1}')
        $cmd -D DOCKER-USER "$line"
    done
}

flush iptables
flush ip6tables

# Inserted in reverse order of evaluation: the DROP goes in first and ends
# up at the bottom of the rules this script owns, with every ACCEPT above
# it. A packet from Cloudflare matches an ACCEPT and leaves the chain;
# anything else arriving on the public interface falls through to the DROP.
iptables  -I DOCKER-USER -i "$EXT_IF" -p tcp -m multiport --dports "$PORTS" -m comment --comment "$TAG" -j DROP
ip6tables -I DOCKER-USER -i "$EXT_IF" -p tcp -m multiport --dports "$PORTS" -m comment --comment "$TAG" -j DROP

for range in $V4; do
    iptables -I DOCKER-USER -i "$EXT_IF" -s "$range" -p tcp -m multiport --dports "$PORTS" \
        -m comment --comment "$TAG" -j ACCEPT
done

for range in $V6; do
    ip6tables -I DOCKER-USER -i "$EXT_IF" -s "$range" -p tcp -m multiport --dports "$PORTS" \
        -m comment --comment "$TAG" -j ACCEPT
done

# Replies to connections this host opened are not new inbound traffic and
# must not be caught by the DROP above.
iptables  -I DOCKER-USER -m state --state RELATED,ESTABLISHED -m comment --comment "$TAG" -j RETURN
ip6tables -I DOCKER-USER -m state --state RELATED,ESTABLISHED -m comment --comment "$TAG" -j RETURN

echo "Ports $PORTS restricted to Cloudflare."
echo "SSH is untouched - it is not container traffic, so ufw still governs it."
