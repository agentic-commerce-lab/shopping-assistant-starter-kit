<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests;

/**
 * Hides this machine's model credentials from a test, and puts them back afterwards.
 *
 * Three test bases needed exactly this and each had its own copy — one of them commented "same guard
 * as SystemConfigLlmSettingsTest", which is a duplicate admitting to being one. It became worth
 * extracting when the guard had to get wider.
 *
 * **All three sources, and that is the point.** `SystemConfigLlmSettings` reads the process
 * environment *and* `$_ENV` *and* `$_SERVER`, because Symfony's runtime boots Dotenv with
 * `usePutenv(false)`: a variable written into `.env` or `.env.local` reaches only the superglobals,
 * and reading `getenv()` alone left the documented environment-variable route silently broken for
 * every operator who used a file. A guard that clears one source therefore hid a developer's real
 * key in the other two — four tests failed on a credentialed machine and passed in CI, which has no
 * `.env` at all. The suite's own `tests/bootstrap.php` loads `.env` into all three when the file
 * exists, so the guard has to reach all three too.
 */
trait LlmEnvironmentGuard
{
    /** @var list<string> */
    private const GUARDED_ENV_NAMES = ['ASSISTANT_LLM_BASE_URL', 'ASSISTANT_LLM_MODEL', 'ASSISTANT_LLM_API_KEY'];

    /** @var array<string, string|false> */
    private array $guardedProcessEnv = [];

    /** @var array<string, array{env: mixed, server: mixed}> */
    private array $guardedSuperglobals = [];

    protected function clearLlmEnvironment(): void
    {
        foreach (self::GUARDED_ENV_NAMES as $name) {
            $this->guardedProcessEnv[$name] = getenv($name);
            $this->guardedSuperglobals[$name] = [
                'env' => $_ENV[$name] ?? null,
                'server' => $_SERVER[$name] ?? null,
            ];

            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    protected function restoreLlmEnvironment(): void
    {
        foreach ($this->guardedProcessEnv as $name => $value) {
            if (\is_string($value)) {
                putenv(\sprintf('%s=%s', $name, $value));
            }
        }

        foreach ($this->guardedSuperglobals as $name => $saved) {
            if ($saved['env'] !== null) {
                $_ENV[$name] = $saved['env'];
            }

            if ($saved['server'] !== null) {
                $_SERVER[$name] = $saved['server'];
            }
        }
    }
}
