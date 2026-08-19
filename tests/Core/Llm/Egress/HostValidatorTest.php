<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Llm\Egress;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Llm\Egress\HostValidator;

final class HostValidatorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function rejectedHosts(): iterable
    {
        yield 'localhost' => ['localhost'];
        yield 'mdns' => ['printer.local'];
        yield 'loopback v4' => ['127.0.0.1'];
        yield 'loopback v6' => ['[::1]'];
        yield 'private 10/8' => ['10.0.0.5'];
        yield 'private 192.168/16' => ['192.168.1.10'];
        yield 'private 172.16/12' => ['172.16.0.9'];
        yield 'link local' => ['169.254.169.254'];
        yield 'empty' => [''];
    }

    #[DataProvider('rejectedHosts')]
    public function testRejectsNonPublicHosts(string $host): void
    {
        self::assertFalse(HostValidator::isPublicHost($host));
    }

    public function testAcceptsAPublicIpLiteral(): void
    {
        self::assertTrue(HostValidator::isPublicHost('1.1.1.1'));
    }
}
