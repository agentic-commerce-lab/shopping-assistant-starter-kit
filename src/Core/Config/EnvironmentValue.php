<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Config;

/**
 * Reads an environment variable from all three places one can actually be, because `getenv()` is
 * only one of them.
 *
 * **`getenv()` alone made the documented environment-variable route a trap.** It reads the real
 * process environment and nothing else, while Symfony's runtime boots Dotenv with
 * `usePutenv($options['use_putenv'] ?? false)` — so a variable written into `.env` or `.env.local`,
 * which is where a Shopware operator puts one, lands in `$_ENV` and `$_SERVER` and stays invisible.
 * Measured on 6.7.13.1: `ASSISTANT_DOC_PROBE=hello` in `.env` gave `getenv(…) === false` beside
 * `$_ENV[…] === 'hello'`.
 *
 * The failure that produced was the quiet kind. The key is in the file, the shop reports itself
 * unconfigured, `/assistant/chat` answers 503, the orb never renders, and nothing in any log says
 * why. `composer run test:eval` worked the whole time, because `Shopware\Core\TestBootstrapper` is
 * the one caller in the stack that does `(new Dotenv())->usePutenv()` — exactly the false confidence
 * that kept this hidden.
 *
 * **Its own class rather than a private method** on {@see SystemConfigLlmSettings}: that class is at
 * the complexity the gate allows, and this is a separate piece of knowledge anyway — where an
 * environment variable lives has nothing to do with how a sales channel's model is configured.
 * `Command\ProbeTurnRunner` reads the same three variables through `getenv()` alone and has the same
 * bug; it should come here too.
 */
final readonly class EnvironmentValue
{
    /**
     * The variable's value, trimmed, or `''` when it is unset or blank everywhere.
     *
     * Order is deliberate: the real process environment wins. That is the one a host operator sets
     * deliberately — Docker `environment:`, a systemd unit, an fpm pool — and it must not be
     * overridable by a file somebody left in the project directory.
     */
    public static function of(string $name): string
    {
        // `?:` rather than `??` down the chain: a blank value has to fall through to the next source
        // the same way an absent one does, because every caller here treats blank as unset. A
        // non-string in a superglobal falls out at the `is_string()`.
        $value = getenv($name) ?: $_ENV[$name] ?? null ?: $_SERVER[$name] ?? null ?: '';

        return \is_string($value) ? trim($value) : '';
    }
}
