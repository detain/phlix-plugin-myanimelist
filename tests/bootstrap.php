<?php

/**
 * Bootstrap.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

/**
 * Test bootstrap for phlix-plugin-myanimelist.
 *
 * Requires Composer's autoloader. The plugin depends on detain/phlix-shared
 * which provides Phlix\Shared\Plugin\LifecycleInterface — no stub needed there.
 *
 * ## Server-interface stub
 *
 * MyanimelistMetadataProviderAdapter implements the HOST contract
 * `Phlix\Media\Metadata\MetadataProviderInterface`, which lives in the
 * `phlix-server` repo (PSR-4 `Phlix\` => `src/`) — NOT in phlix-shared and NOT
 * in this plugin's vendor. In the resident-memory server runtime the interface
 * resolves via the already-registered server autoloader (see the class docblock
 * on MyanimelistMetadataProviderAdapter). In CI the server is absent, so we
 * define a minimal stub of the EXACT same FQCN/signature here so the adapter
 * class can load and be exercised.
 *
 * Keep this signature byte-for-byte in sync with
 * phlix-server/src/Media/Metadata/MetadataProviderInterface.php.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!interface_exists(\Phlix\Media\Metadata\MetadataProviderInterface::class, false)) {
    require __DIR__ . '/Stub/MetadataProviderInterface.php';
}
