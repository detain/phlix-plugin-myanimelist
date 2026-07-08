<?php

/**
 * Stub Metadataproviderinterface.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

/**
 * TEST-ONLY stub of the host server's metadata-provider contract.
 *
 * This is a verbatim signature copy of
 * `phlix-server/src/Media/Metadata/MetadataProviderInterface.php`. It exists
 * ONLY so the plugin's unit suite can load
 * {@see \Phlix\Myanimelist\MyanimelistMetadataProviderAdapter} (which implements
 * this exact FQCN) without a checkout of phlix-server. At runtime inside the
 * resident server process the REAL interface is provided by the server
 * autoloader and this file is never loaded — the bootstrap guards on
 * `interface_exists()`.
 *
 * If the upstream interface changes, update this stub to match.
 *
 * @internal Test fixture only — not shipped/autoloaded outside the test suite.
 */
interface MetadataProviderInterface
{
    public const MEDIA_TYPE_ALBUM = 'album';
    public const MEDIA_TYPE_ARTIST = 'artist';
    public const MEDIA_TYPE_TRACK = 'track';

    /**
     * @param string $query
     * @param array<string, mixed> $options
     * @return array<int, array{id: string, title: string, overview?: string, poster_path?: string}>
     */
    public function search(string $query, array $options = []): array;

    /**
     * @param string $externalId
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getDetails(string $externalId, array $options = []): array;

    /**
     * @param string $externalId
     * @return array<string, array<int, array{url: string, width?: int, height?: int}>>
     */
    public function getImages(string $externalId): array;

    /**
     * @return array<string>
     */
    public function getProviders(): array;

    /**
     * @return string
     */
    public function getSourceName(): string;
}
