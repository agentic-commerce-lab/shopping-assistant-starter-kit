<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Llm\Egress;

final class HostValidator
{
    public static function isPublicHost(string $host): bool
    {
        $normalized = strtolower(trim($host, " \t\n\r\0\x0B[]"));

        if ($normalized === '' || $normalized === 'localhost' || str_ends_with($normalized, '.local')) {
            return false;
        }

        $addresses = DnsResolver::resolve($normalized);

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (!self::isPublicAddress($address)) {
                return false;
            }
        }

        return true;
    }

    private static function isPublicAddress(string $address): bool
    {
        return (
            filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) !== false
        );
    }
}
