<?php

/**
 * Myanimelistmetadataprovideradapter.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Myanimelist;

use Phlix\Media\Metadata\MetadataProviderInterface;

/**
 * Host-interface adapter that exposes {@see MyanimelistMetadataProvider} through the
 * server's {@see \Phlix\Media\Metadata\MetadataProviderInterface} contract so
 * the host {@see \Phlix\Media\Metadata\MetadataManager} can actually consume
 * MAL metadata.
 *
 * ## Why an adapter (and not implement the interface on the provider directly)?
 *
 * The provider ({@see MyanimelistMetadataProvider}) owns the MAL HTTP session
 * (rate limiting, caching) and a `lookup(string $filePath)` shaped for
 * filename-based matching. The host registry instead drives providers via a
 * `search()/getDetails()/getImages()` triad keyed by an *external id*. This
 * thin adapter bridges the two without entangling the HTTP logic with the host
 * contract.
 *
 * ## Reachability of `MetadataProviderInterface`
 *
 * `Phlix\Media\Metadata\MetadataProviderInterface` lives in the `phlix-server`
 * repo (PSR-4 `Phlix\` => `src/`), NOT in `phlix-shared`. At runtime that is fine:
 * Phlix is a resident-memory Workerman process, so the server's Composer
 * autoloader is already registered when `PluginLoader::enable()` `require_once`'s
 * the plugin's own `vendor/autoload.php`. Requiring the plugin autoloader ADDS
 * the `Phlix\Myanimelist\` prefix; it never unregisters the server's
 * `Phlix\` => `src/` mapping, so this interface resolves in production.
 *
 * For unit tests (where the server is absent) the test bootstrap defines a
 * minimal stub of the exact same FQCN — see `tests/bootstrap.php`.
 *
 * ## External-id convention
 *
 * The "external id" handed back from `search()` and consumed by
 * `getDetails()`/`getImages()` is the MAL anime ID rendered as a decimal
 * string (e.g. `"1"`). `getDetails()` parses it back to an int and fetches the
 * full anime record via the wrapped provider.
 *
 * @package Phlix\Myanimelist
 * @since 0.2.0
 */
final class MyanimelistMetadataProviderAdapter implements MetadataProviderInterface
{
    /**
     * Canonical source name advertised to the host registry.
     */
    public const SOURCE_NAME = 'myanimelist';

    /**
     * The wrapped MAL provider that owns the HTTP session and lookup logic.
     */
    private MyanimelistMetadataProvider $provider;

    /**
     * @param MyanimelistMetadataProvider $provider Live, already-enabled provider.
     */
    public function __construct(MyanimelistMetadataProvider $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Search MAL for anime matching a free-text query (e.g. a series title).
     *
     * Resolves the query to a MAL ID via the wrapped provider's search path,
     * then returns it as a single best-match result in the host's expected
     * shape. MAL's search API returns ranked results; we take the first.
     *
     * @param string               $query   Search query (e.g. anime title).
     * @param array<string, mixed> $options Search options (year/language); unused
     *                                       by MAL but accepted for contract parity.
     *
     * @return array<int, array{id: string, title: string, overview?: string, poster_path?: string}>
     *         Zero or one search result.
     */
    public function search(string $query, array $options = []): array
    {
        $malId = $this->provider->findIdByTitle($query);
        if ($malId === null) {
            return [];
        }

        $details = $this->provider->fetchAnimeMetadata($malId);
        if ($details === []) {
            // We have a MAL ID but could not enrich it; still return a usable stub
            // so the host can call getDetails() with the id.
            return [[
                'id'    => (string) $malId,
                'title' => $query,
            ]];
        }

        $result = [
            'id'    => (string) $malId,
            'title' => self::stringOr($details['title'] ?? null, $query),
        ];

        $overview = $details['overview'] ?? null;
        if (is_string($overview) && $overview !== '') {
            $result['overview'] = $overview;
        }

        $poster = $details['poster_url'] ?? null;
        if (is_string($poster) && $poster !== '') {
            $result['poster_path'] = $poster;
        }

        return [$result];
    }

    /**
     * Fetch the full MAL metadata record for an external id (the MAL anime ID).
     *
     * @param string               $externalId MAL anime ID as a decimal string.
     * @param array<string, mixed> $options    Additional options (language); unused.
     *
     * @return array<string, mixed> Detailed metadata, or `[]` when not found.
     */
    public function getDetails(string $externalId, array $options = []): array
    {
        $malId = self::parseMalId($externalId);
        if ($malId === null) {
            return [];
        }

        return $this->provider->fetchAnimeMetadata($malId);
    }

    /**
     * Fetch image URLs for an external id (the MAL anime ID), grouped by image type.
     *
     * MAL provides a poster (`main_picture`) and additional pictures. The first
     * additional picture is returned as fanart; all are surfaced under their
     * respective groups.
     *
     * @param string $externalId MAL anime ID as a decimal string.
     *
     * @return array<string, array<int, array{url: string, width?: int, height?: int}>>
     *         Images keyed by type (`poster`, `fanart`).
     */
    public function getImages(string $externalId): array
    {
        $malId = self::parseMalId($externalId);
        if ($malId === null) {
            return [];
        }

        $details = $this->provider->fetchAnimeMetadata($malId);
        $poster = $details['poster_url'] ?? null;
        $fanart = $details['fanart_url'] ?? null;

        $result = [];
        if (is_string($poster) && $poster !== '') {
            $result['poster'] = [['url' => $poster]];
        }
        if (is_string($fanart) && $fanart !== '') {
            $result['fanart'] = [['url' => $fanart]];
        }

        return $result;
    }

    /**
     * Provider-name aliases this implementation answers to.
     *
     * @return array<string> Always `['myanimelist']`.
     */
    public function getProviders(): array
    {
        return [self::SOURCE_NAME];
    }

    /**
     * Canonical source name of this provider.
     *
     * @return string Always `'myanimelist'`.
     */
    public function getSourceName(): string
    {
        return self::SOURCE_NAME;
    }

    /**
     * Parse a decimal MAL anime ID string into a positive int, or null when invalid.
     */
    private static function parseMalId(string $externalId): ?int
    {
        $trimmed = trim($externalId);
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return null;
        }
        $malId = (int) $trimmed;
        return $malId > 0 ? $malId : null;
    }

    /**
     * Return $value as a non-empty string, otherwise the fallback.
     */
    private static function stringOr(mixed $value, string $fallback): string
    {
        return (is_string($value) && $value !== '') ? $value : $fallback;
    }
}
