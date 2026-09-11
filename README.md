# TTPHP

A peer to peer marketplace for buying and selling secondhand musical instruments in the UK. Laravel API, React frontend, MySQL, Elasticsearch.

Work in progress.

## Stack

**Backend:** PHP 8.3, Laravel 12, Sanctum for token auth, Reverb for WebSockets, MySQL 8, Elasticsearch 8

**Frontend:** React 19 with TypeScript, SCSS, `@stomp` free (Laravel Echo over Reverb), no UI framework

**Tooling:** Composer, Docker Compose, GitHub Actions, PHPUnit, Pint

## Running it

Everything comes up with Compose:

```bash
docker compose up --build
```

* `http://localhost:8080` the app
* `ws://localhost:8081` Reverb, for live messaging
* `http://localhost:9200` Elasticsearch

Set `ELASTICSEARCH_PORT` if something already holds 9200 on your machine.

Running the pieces directly instead:

```bash
php artisan serve
php artisan reverb:start
cd frontend && npm start
```

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

## Configuration

| Variable | Purpose | Default |
|---|---|---|
| `ELASTICSEARCH_ENABLED` | Turn search on. Off means the SQL fallback. | `false` |
| `ELASTICSEARCH_HOST` | Cluster URL | `http://localhost:9200` |
| `ELASTICSEARCH_INDEX` | Index name, so environments can share a cluster | `listings` |
| `ELASTICSEARCH_TIMEOUT` | Request timeout in seconds | `2.0` |
| `ELASTICSEARCH_PORT` | Host port Compose publishes the cluster on | `9200` |
