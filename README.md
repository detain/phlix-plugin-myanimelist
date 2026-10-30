# phlix-plugin-myanimelist

[![tests](https://github.com/detain/phlix-plugin-myanimelist/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-myanimelist/actions/workflows/test.yml)

> MyAnimeList metadata provider plugin for [Phlix](https://github.com/detain/phlix)
> — anime titles, descriptions, episodes, ratings via the MyAnimeList API v2.

## Overview

This plugin fetches structured anime metadata from [MyAnimeList](https://myanimelist.net/)
using the official **MAL API v2** (`https://api.myanimelist.net/v2`):

1. **Search** (`GET /anime?q=...`) — resolve a title to a MAL anime ID (best match)
2. **Details** (`GET /anime/{id}?fields=...`) — fetch titles, synopsis, episodes, rating, studio

Every request carries an `X-MAL-CLIENT-ID` header with your MAL client ID.

## Features

- **Title search** — query MAL for the best-matching anime ID
- **Full metadata** — primary/English/Japanese titles, synonyms, genres, year, type, rating
- **Episode info** — episode count and average episode runtime
- **Synopsis** — long-form description text
- **No SDK** — plain HTTP/JSON against the MAL REST API via the vendored
  [`workerman/http-client`](https://github.com/walkor/http-client) async client
  (`Workerman\Http\Client` over pooled HTTPS), not PHP stream wrappers. Runtime
  requires: `workerman/http-client`, `detain/phlix-shared`, `psr/container`,
  `psr/log` — see `composer.json` (which is why Install step 4 runs `composer install`).

## Install

The plugin is unsigned by design. Install via the Phlix admin UI:

1. Log in to your Phlix server as an admin user (`users.is_admin = 1`).
2. Browse to `/admin/plugins`.
3. Paste this URL into the **Install from URL** form:

   ```
   https://raw.githubusercontent.com/detain/phlix-plugin-myanimelist/master/plugin.json
   ```

4. The server downloads and validates the manifest, runs `composer install --no-dev`, and stores a row in the `plugins` table.
5. Configure your MyAnimeList client ID in the plugin settings form.
6. Enable the plugin.

### Getting a MAL Client ID

1. Sign in at [myanimelist.net](https://myanimelist.net/).
2. Go to [API → Create ID](https://myanimelist.net/apiconfig).
3. Create an app and copy the **Client ID** (the Client Secret is not needed for read-only metadata).

## Configuration

Configure these in the Phlix admin **Plugins → Configure** dialog.

| Setting | Type | Required | Default | Description |
|---------|------|----------|---------|-------------|
| `client_id` | string (secret) | **Yes** | — | Your MyAnimeList API Client ID, sent as the `X-MAL-CLIENT-ID` header. |
| `use_ssl_verification` | boolean | No | `true` | Verify TLS certificates when calling the MAL API. |

The plugin validates `client_id` when it is constructed: enabling it with a
blank or missing Client ID throws immediately at that boundary instead of
storing the credential and failing later on every request.

### Where to get your Client ID

Create an API application at [myanimelist.net/apiconfig](https://myanimelist.net/apiconfig)
(App Type "web" or "other"), then copy its **Client ID** into `client_id`.

## How It Works

`onEnable` registers the {@see MyanimelistMetadataProviderAdapter} with the host's
MetadataManager, which then drives the standard triad against the adapter:

1. **Search** — `GET /anime?q=<title>&limit=10&fields=id,title,alternative_titles`. Every candidate
   is scored against all of its titles (exact > prefix > contains, including English/Japanese/synonym
   alternatives) and the single best match is returned — MAL's popularity ranking is not taken at
   face value.
2. **Details** — `GET /anime/{id}?fields=...` for full anime data.
3. **Images** — poster (`main_picture`) + fanart (first `pictures` entry).

The legacy `lookup($filePath)` entry point (filename parsing → search → details) is still
implemented for interface parity, but the host does not call it.

## MAL API Notes

- **Protocol**: REST/JSON over HTTPS to `https://api.myanimelist.net/v2`
- **Auth**: every request sends `X-MAL-CLIENT-ID: <client_id>`
- **Redirects**: refused outright (`allow_redirects => ['max' => 0]`) — the credential header is never
  re-issued to a redirect target; a 3xx is treated as a failed lookup
- **Search**: `GET /anime?q=<query>&limit=10&fields=id,title,alternative_titles` → `{ "data": [ { "node": { "id", "title", ... } } ] }`
- **NSFW default-deny**: requests never send `nsfw`/`nsfw_all`, so MAL omits
  adult entries from search and refuses them via details — adult variants
  simply never match. The details `rating` classification (`g`, `pg-13`,
  `r-17`, `rx`) is still surfaced as `adult_rating` on the metadata map so a
  host that resolves entries by other means can apply its own gate
- **Details**: `GET /anime/{id}?fields=id,title,main_picture,alternative_titles,start_date,synopsis,mean,num_scoring_users,genres,num_episodes,media_type,status,studios,average_episode_duration,rating,pictures` (`pictures` is what makes `fanart_url` possible — drop it and fanart silently becomes null)
- **`fields=` is the headline MAL gotcha**: MAL returns nested data
  (`alternative_titles`, `genres`, `studios`, `pictures`, …) only for fields
  that are explicitly listed in the `fields=` parameter, on both the details
  and search endpoints. Trimming a field from the list fails SILENTLY —
  no error, no warning — the metadata just degrades: blank genres/studio,
  null fanart, scoring reduced to main-title matching (English/Japanese/
  synonym alternatives disappear), and `adult_rating` vanishes. Every key
  the parsers read is requested; if you edit `DETAIL_FIELDS` or the search
  `fields=`, keep the consumed set complete.
- **Rate limiting**: ~1 request/second; a `Retry-After` on 429/503 is honored
  but clamped to 300s (non-finite header values fall back to the default
  interval) so one pathological response can never park a resident worker
- **Caching**: decoded 2xx bodies are cached per URL for 1h (TTL) with the
  cache capped at 512 entries (oldest evicted); the cache is also dropped on
  disable, so bodies fetched under a rotated `client_id` are not replayed

See the [MAL API v2 reference](https://myanimelist.net/apiconfig/references/api/v2) for full details.

## Data Returned

```php
[
    'title'         => 'Cowboy Bebop',          // Primary title
    'original_name' => 'カウボーイビバップ',       // Japanese title (falls back to title)
    'overview'      => 'In the year 2071...',   // Synopsis
    'year'          => 1998,                     // First-aired year
    'genres'        => ['Action', 'Sci-Fi'],     // Genre names
    'rating'        => 8.75,                     // MAL mean score (0-10)
    'vote_count'    => 900000,                   // Number of scoring users
    'poster_url'    => 'https://cdn.myanimelist.net/images/anime/4/19644l.jpg',
    'fanart_url'    => 'https://cdn.myanimelist.net/images/anime/1904/120844.jpg', // first `pictures` entry (large preferred, medium fallback); null when the anime has none
    'episodes'      => 26,                        // Episode count
    'type'          => 'tv',                      // tv / movie / ova / special / ona / music
    'mal_id'        => 1,                         // MyAnimeList anime ID
    'titles'        => ['Cowboy Bebop', 'カウボーイビバップ', 'Cowboy Bebop (1998)'],
    'status'        => 'Finished',               // Finished / Currently Airing / Upcoming
    'runtime_ticks' => 14400000000,              // Avg episode length in ticks (1s = 10,000,000)
    'studio'        => 'Sunrise',                // First studio name
]
```

A no-match returns `[]`.

## Fork as a Starter

This plugin is based on [`phlix-plugin-example`](https://github.com/detain/phlix-plugin-example). To create your own metadata provider:

1. Fork or copy this repository.
2. Edit `plugin.json` — pick a new `name` (must start with `phlix-plugin-`), set `version` to your own starting version (a new fork begins its own line, e.g. `0.1.0` — this repository is already at `0.3.0`, so "start at 0.1.0" is a fresh line, not a downgrade), change `entry` to your FQCN.
3. Edit `composer.json` — rename the package, update PSR-4 autoload prefix.
4. Replace `src/MyanimelistMetadataProvider.php` with your own implementation — and handle `src/MyanimelistMetadataProviderAdapter.php` too: its `use` statements and type-hints pin the provider FQCN, so it must be replaced or renamed along with the provider it wraps.
5. Run tests: `composer install && vendor/bin/phpunit`.

## Testing

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpunit --testdox  # verbose output
```

The unit tests exercise the parse/map helpers via Reflection with fixture
JSON — no live network calls are made; the HTTP paths covered by
`tests/Unit/MyanimelistTransportTest.php` and
`tests/Unit/MyanimelistMetadataProviderAdapterTest.php` run through anonymous
`\Workerman\Http\Client` subclasses instead.

`phpunit.xml` declares a Cobertura report, so a run with a coverage driver also
writes `coverage.xml`; `.github/workflows/test.yml` runs the suite on PHP `8.3`
and `8.4` and uploads that file to Codacy.

### Static analysis (PHPStan level 9)

The CI workflow gates `src/` at PHPStan **level 9** with no exclusions:

```bash
vendor/bin/phpstan analyse src --level=9   # the CI command verbatim
vendor/bin/phpstan analyse -c phpstan.neon # same result, config-driven
```

`phpstan.neon` pins `level: 9`, `paths: [src]`, and bootstraps
`tests/Stub/MetadataProviderInterface.php` — the same verbatim test stub
`tests/bootstrap.php` loads — so the adapter (which implements the host
server's metadata contract, absent from this repo's dependencies) analyzes
cleanly instead of being excluded. Both invocations resolve the same
configuration: the explicit `--level=9` command still auto-loads
`phpstan.neon` for `bootstrapFiles`.

## Security Audit

`.github/workflows/test.yml` also runs a blocking `composer-audit` job that
executes `scripts/security-audit-check.php`. It audits the whole lock — both
`require` and `require-dev` — via `composer audit --locked --format=json`,
labels each finding `[require]` or `[require-dev]`, and fails if the audited
corpus drops below its floors:

```bash
php scripts/security-audit-check.php
```

There is no baseline file and no ignore list in the script; the only escape
hatch is `config.audit.ignore` in `composer.json`, which the gate reports back
as a loud IGNORED notice. `tests/Unit/SecurityAuditCheckTest.php` pins that
policy shut.

## License

MIT — see [`LICENSE`](LICENSE).
