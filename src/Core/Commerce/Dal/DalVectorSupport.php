<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Doctrine\DBAL\Connection;
use Swag\AssistantStarterKit\Core\ShopInfo\VectorSupport;

/**
 * Asks the shop's own database whether it can do what the passage store needs.
 *
 * **A probe, not a version comparison.** `VERSION()` returns strings a shop can rewrite, a proxy can
 * forge and a fork can spell its own way, and the answer that matters is not "which product is this"
 * but "does `VEC_DISTANCE_COSINE` exist here". Running the function the store actually calls answers
 * that directly, and keeps working on a MariaDB fork or a version number nobody predicted.
 *
 * Read-only and cheap: one `SELECT` over two literal vectors, no table touched. Memoised per request
 * because {@see \Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig} is built once per
 * turn and would otherwise probe on every one.
 *
 * A failure of any kind means unsupported. That is deliberately blunt — a database this cannot ask
 * is a database the store should not be pointed at either, and the alternative is letting an
 * exception out of a capability check that exists to prevent exceptions.
 */
final class DalVectorSupport implements VectorSupport
{
    private ?bool $available = null;

    private ?string $version = null;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            $this->connection->fetchOne("SELECT VEC_DISTANCE_COSINE(VEC_FromText('[1,0]'), VEC_FromText('[0,1]'))");
            $this->available = true;
        } catch (\Throwable) {
            $this->available = false;
        }

        return $this->available;
    }

    /** What the shop runs, so an unavailability message diagnoses rather than restates. */
    public function describe(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        try {
            $version = $this->connection->fetchOne('SELECT VERSION()');
            $this->version = \is_string($version) && $version !== '' ? $version : 'an unknown database';
        } catch (\Throwable) {
            $this->version = 'an unknown database';
        }

        return $this->version;
    }
}
