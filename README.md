# Restrum

A peer to peer marketplace for buying and selling secondhand musical
instruments in the UK. Sellers list gear with photos, audio and video, buyers
search it, and the two talk in real time. Every instrument carries a service
history that moves with it between owners.

This is the PHP build. The parts worth reading are the Elasticsearch analyzers
behind gear search, the queued indexing, and how search degrades to SQL when
the cluster is unavailable. All three are explained below.

## Stack

**Backend:** PHP 8.3, Laravel 13, Sanctum for token auth, Reverb for WebSockets, MySQL 8, Elasticsearch 8, Redis for queued work

**Frontend:** React 19 with TypeScript, SCSS, Laravel Echo over Reverb for live messaging, no UI framework

**Tooling:** Composer, Docker Compose, GitHub Actions, PHPUnit, Pint

## Running it

Everything comes up with Compose:

```bash
docker compose up --build
```

* `http://localhost:8080` the app
* `ws://localhost:8081` Reverb, for live messaging
* `http://localhost:9200` Elasticsearch

Redis and a queue worker run alongside, with no ports of their own.

Set `ELASTICSEARCH_PORT` if something already holds 9200 on your machine.

Running the pieces directly instead:

```bash
php artisan serve --port=8500
php artisan reverb:start
cd frontend && npm start
```

Port 8500 rather than Laravel's default 8000 because that is what the frontend
looks for when `REACT_APP_API_BASE_URL` is unset, in `frontend/src/lib/config.ts`.

## Search

Listing search runs on Elasticsearch, with the raw client rather than Scout,
because the interesting part is the analyzers and the query rather than the
plumbing around them.

Build the index:

```bash
php artisan search:reindex --fresh
```

`--fresh` drops and recreates it, which is how a mapping change gets applied:
mappings are immutable once a field has been indexed. Without the flag the
command leaves an existing index in place and refills it.

After that the index maintains itself. `ListingSearchObserver` writes on save
and delete, so a reindex is only needed after a mapping change or to recover
from a cluster being rebuilt.

### Why it is not a LIKE query

The obvious implementation is `WHERE title LIKE '%term%'`, and it was the
implementation until Elasticsearch went in. It cannot use an index, so it
reads every row, and it matches substrings rather than words, so it has no
notion of relevance, typos, or what a thing is called in practice.

Three analyzers cover how people actually search for gear:

* **Prefixes.** An edge ngram filter indexes `stra`, `strat`, `strato` and so
  on, so "strat" finds a Stratocaster. Index time only: applying it at search
  time too would turn a three letter query into a match on everything.
* **Slang and stemming.** A synonym filter maps "mic" to microphone, "tele" to
  Telecaster, "amp" to amplifier and combo, then an English stemmer collapses
  plurals. The category is indexed twice, once as a keyword for filtering and
  once analysed, so searching "synth" finds things filed under synths even
  when the title never says the word.
* **Model numbers.** A word delimiter filter splits on the letter to digit
  boundary and keeps the original, so "SM58", "sm58" and "sm 58" all reach the
  same microphone.

On top of that the query applies `fuzziness: AUTO`, so "telecastor" still
finds a Telecaster. Title matches are boosted over description matches, an
exact phrase in a title outranks everything, and sold listings sort below
available ones.

### Timings

`php artisan search:benchmark` compares the two paths over whatever is in the
database. Against 20,000 listings, twenty five runs per term, medians:

| Term | SQL LIKE | Elasticsearch | Speedup | Hits (SQL / ES) |
|---|---|---|---|---|
| strat | 27.2 ms | 10.1 ms | 2.7x | 960 / 960 |
| telecaster | 27.9 ms | 10.6 ms | 2.6x | 944 / 944 |
| microphone | 30.4 ms | 11.0 ms | 2.8x | 0 / 3967 |
| delay pedal | 27.0 ms | 9.3 ms | 2.9x | 0 / 0 |
| fender | 23.9 ms | 9.9 ms | 2.4x | 1011 / 1011 |

Two things worth noting. The speedup is the less interesting number: the SQL
path scans the whole table, so it grows with the table while the Elasticsearch
figure stays roughly flat, and at 20,000 rows that gap is only beginning to
open. The `microphone` row is the more honest argument for the change. SQL
returns nothing because no title contains the word, while Elasticsearch
returns every microphone listing.

### Similar listings

The listing page carries a "you might also like" rail, fed by a
`more_like_this` query against the same index rather than by "other things
in this category". Elasticsearch picks out the terms that make a listing
distinctive, judged by how rare they are across the corpus, so a
Stratocaster is pulled towards other Stratocasters rather than towards
everything with "Fender" in the title, and none of it is hand tuned.

Suggestions are filtered to the same category and to listings that are still
for sale, and the source listing is excluded. The endpoint is separate from
`show` on purpose: recommendations are the least important thing on the page
and should never delay it, and when search is off or failing the rail
returns nothing and disappears rather than falling back to a worse guess.

Worth knowing when trying it locally: `more_like_this` needs a corpus. With
the handful of listings in a development database almost no term is rare
enough to clear the threshold, so results are sparse or empty. At 2,000
listings it behaves as intended, which is what `SimilarListingsTest` and a
seeded run were used to confirm.

### Media storage

Uploads go to whichever disk `MEDIA_DISK` names, defaulting to the local
public disk. Anywhere with a container filesystem it must point at object
storage, because local disk does not survive a restart and a seller's photos
vanishing on the next deploy is data loss the app cannot detect.

The database stores the path, not the URL, and the URL is computed from the
disk in use. Moving media to a bucket or behind a CDN is a config change
rather than a rewrite of every row.

### Indexing happens on a queue

A save dispatches `IndexListing` rather than writing to Elasticsearch
inline, and a delete dispatches `RemoveListingFromIndex`. The index write
asks the cluster to make the document searchable before returning, which is
the slowest way to index on purpose: worth having, but not while somebody
waits for their page. The worker does the waiting instead.

The index is therefore eventually consistent with the database, by however
long the queue is. That is the right trade here, since a listing becoming
searchable a moment late is imperceptible and a slower save is not.

The Compose stack runs Redis and a `queue-worker` service for this. Locally
the default is Laravel's database queue, so nothing extra is needed to run
the app, and `php artisan queue:work` processes the jobs.

### When the cluster is down

Search degrades rather than breaks. `ELASTICSEARCH_ENABLED=false` puts the
original SQL path back, and a cluster that is enabled but unreachable is
caught, logged, and falls back to the same path, so browsing keeps working
with worse relevance. Indexing failures on save are swallowed for the same
reason: a seller posting an instrument should never see a 500 because a
search cluster is unhappy, and the row is still in MySQL for the next
reindex to pick up.

This is covered by tests rather than assumed, in `ListingSearchFallbackTest`.

## Tests

```bash
php artisan test
```

The suite runs against MySQL rather than sqlite, because the schema uses MySQL
enum columns and the two engines disagree about the clock in ways that have
already hidden one real bug.

The search tests need a live Elasticsearch and skip themselves when there
isn't one, so a clone with no Docker still gets a green suite. CI provides a
service container so they run there for real.

`QueryCountTest` guards the busiest endpoints against N+1 queries. It runs
each request with one row and then with four and fails if the query count
moved, which is deterministic in a way a timing threshold is not. It caught
one straight away: the inbox ran an endorsement lookup per conversation.

Search is switched off for the suite by default and pinned to a throwaway
index, both forced in `phpunit.xml` so a developer's own `.env` cannot
override them. The tests that need search turn it on themselves. Without
that, every factory listing made an indexing round trip, and worse, wrote
into the index the development app was reading from.

## Configuration

| Variable | Purpose | Default |
|---|---|---|
| `ELASTICSEARCH_ENABLED` | Turn search on. Off means the SQL fallback. | `false` |
| `ELASTICSEARCH_HOST` | Cluster URL | `http://localhost:9200` |
| `ELASTICSEARCH_INDEX` | Index name, so environments can share a cluster | `listings` |
| `ELASTICSEARCH_TIMEOUT` | Request timeout in seconds | `2.0` |
| `ELASTICSEARCH_PORT` | Host port Compose publishes the cluster on | `9200` |
| `MEDIA_DISK` | Disk uploaded media is written to | `public` |
| `QUEUE_CONNECTION` | Queue backend. Compose uses `redis`. | `database` |
| `REDIS_CLIENT` | Redis client. `predis`, since no phpredis extension is built. | `predis` |
| `BROADCAST_CONNECTION` | Broadcaster for live messaging. `log` silently discards events. | `reverb` |
| `REVERB_HOST` / `REVERB_PORT` | Where the server reaches Reverb to publish events | `localhost` / `8080` |

The browser reaches Reverb through its own `REACT_APP_REVERB_*` values, which
need not match the pair above: under Compose the server publishes to the
container while the browser connects to the published port. Those are listed in
`frontend/.env.example`, and Create React App bakes them into the bundle at
build time, which is why `docker-compose.yml` passes them as build arguments.
