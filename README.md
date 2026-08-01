# phlix-plugin-myanimelist

[![tests](https://github.com/detain/phlix-plugin-myanimelist/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-myanimelist/actions/workflows/test.yml)

> MyAnimeList metadata provider plugin for [Phlix](https://github.com/detain/phlix)
> — anime titles, descriptions, episodes, ratings via the MyAnimeList API v2.

## Overview

This plugin fetches structured anime metadata from [MyAnimeList](https://myanimelist.net/)
using the official **MAL API v2** (`https://api.myanimelist.net/v2`):

1. **Search** (`GET /anime?q=...`) — resolve a filename to a MAL anime ID
2. **Details** (`GET /anime/{id}?fields=...`) — fetch titles, synopsis, episodes, rating, studio

Every request carries an `X-MAL-CLIENT-ID` header with your MAL client ID.

## Features

- **Title search** — query MAL for the best-matching anime ID
- **Full metadata** — primary/English/Japanese titles, synonyms, genres, year, type, rating
- **Episode info** — episode count and average episode runtime
- **Synopsis** — long-form description text
- **No SDK** — plain HTTP/JSON over the PHP stream wrapper; no extra dependencies

## Install

The plugin is unsigned by design. Install via the Phlix admin UI:

1. Log in to your Phlix server as an admin user (`users.is_admin = 1`).
2. Browse to `/admin/plugins`.
3. Paste this URL into the **Install from URL** form:

   ```
   https://raw.githubusercontent.com/detain/phlix-plugin-myanimelist/main/plugin.json
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

### Where to get your Client ID

Create an API application at [myanimelist.net/apiconfig](https://myanimelist.net/apiconfig)
(App Type "web" or "other"), then copy its **Client ID** into `client_id`.

## How It Works

When the MetadataManager calls `lookup($filePath)`:

1. **Parse filename** — extract anime title from file path (strips S##E##, group tags, resolution suffixes)
2. **Search** — `GET /anime?q=<title>&limit=10` and take the first result's ID
3. **Fetch details** — `GET /anime/{id}?fields=...` for full anime data
4. **Map response** — translate the MAL JSON layout to MetadataManager's expected return shape

## MAL API Notes

- **Protocol**: REST/JSON over HTTPS to `https://api.myanimelist.net/v2`
- **Auth**: every request sends `X-MAL-CLIENT-ID: <client_id>`
- **Search**: `GET /anime?q=<query>&limit=10` → `{ "data": [ { "node": { "id", "title", ... } } ] }`
- **Details**: `GET /anime/{id}?fields=id,title,main_picture,alternative_titles,start_date,synopsis,mean,num_scoring_users,genres,num_episodes,media_type,status,studios,average_episode_duration,rating`

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
    'fanart_url'    => null,                      // MAL has no fanart/backdrop
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
2. Edit `plugin.json` — pick a new `name` (must start with `phlix-plugin-`), bump `version` to `0.1.0`, change `entry` to your FQCN.
3. Edit `composer.json` — rename the package, update PSR-4 autoload prefix.
4. Replace `src/MyanimelistMetadataProvider.php` with your own implementation.
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

## License

MIT — see [`LICENSE`](LICENSE).
