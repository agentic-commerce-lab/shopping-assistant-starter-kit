<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Llm\Egress;

/**
 * Known limitation, inherited from page-agent-shopware: IPv4 only
 * (`gethostbynamel`). An IPv6-only host, or a DNS entry that rebinds between
 * validation and connection, slips through. Accepted for a prototype.
 */
final class DnsResolver
{
    /** @return list<string> */
    public static function resolve(string $host): array
    {
        if (filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        set_error_handler(static fn(): bool => true);

        try {
            $records = gethostbynamel($host);
        } finally {
            restore_error_handler();
        }

        return \is_array($records) ? array_values($records) : [];
    }
}
