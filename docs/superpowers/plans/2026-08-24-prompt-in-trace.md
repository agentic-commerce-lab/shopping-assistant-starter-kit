# Prompt In Trace Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record the system prompt each turn was actually run with, so a merchant debugging a bad answer can see what the model was told — not just what it did.

**Architecture:** One trace event, recorded where the prompt string already exists (`AssistantRunner::buildMessageBag()`), carrying the full text plus a hash and a length. Then a readable rendering in the Administration, because the generic payload viewer would show 3KB of escaped newlines on one line.

**Tech Stack:** PHP 8.2, PHPUnit 11, Shopware Administration (Vue 3 via Shopware's component registry).

**Spec:** No separate design document. The gap was measured on 2026-08-24 against the local test shop and is recorded under *Context* below; this plan is the design of record.

## Context — what is and is not recorded today

88 real turns on the local test shop have produced 18 distinct trace stages:

```
render  validate  grounding.select  facet.probe  tool.call  retrieve  blocklist.filter
turn.end  vocabulary.render  guard.check  retrieve.narrow  understand  query.build
variant.resolve  claims.audit  escalate  turn.tool_limit_exceeded  retrieve.relaxTerm
```

Every stage of the turn is there **except the instructions the model was operating under.** The
nearest thing is `vocabulary.render`, and its stored payload is:

```json
{"fieldCount":8,"valueCount":51,"truncated":true}
```

Statistics about one *section* of the prompt. Not the section, and not the prompt.

Linear's v0 observability clause begins *"Observability out of the box: **prompts**, retrieval, tool
calls, policy checks…"*. Retrieval, tool calls and policy checks have been there since Plan 1.

### Why this got more urgent on 2026-08-23

Until then the prompt varied only by the merchant's `agentVoice` setting, so a merchant could read that
field and mentally reconstruct what the model saw. `PromptProviderInterface` now lets a partner replace
the prompt **wholesale from a different plugin**. Debugging "why did it say that" therefore requires
reading someone else's source — and there is no record of which version of it ran.

That is debt this project created three days ago, and it is cheap to pay.

### Two things this is not

- **Not a privacy expansion.** The system prompt is built from the merchant's config and the
  catalogue's own facet vocabulary. It contains no shopper text: the shopper's message goes into the
  message bag *after* the system message, and the transcript that holds it is a separate column with
  its own retention. Recording the prompt introduces no new class of personal data.
- **Not opt-in.** A debugging aid you must switch on before the failure is no aid at all — the turn
  that went wrong has already happened. It is always recorded, and `traceRetentionDays` bounds the
  storage the same way it bounds everything else here.

### The storage arithmetic, since it is the only real objection

The prompt is roughly 3 KB including the vocabulary block. At the default `dailyRequestCap` of 500
turns per day and `traceRetentionDays` of 30, the worst case is about **45 MB** of prompt text in
`swag_assistant_trace_event` — for a shop running at its own configured ceiling every single day. A
turn's whole trace today is around 2 KB, so this roughly triples trace volume and remains
uninteresting next to the product tables it sits beside. Recorded in full rather than sampled or
hashed-only: a hash tells a merchant the prompt changed, which is not the question they have.

## Global Constraints

- PHP **8.2+**, `declare(strict_types=1)` everywhere, non-disableable.
- `composer run quality` must exit **0**. `excessive-parameter-list` threshold 5, `too-many-methods` past ~15 — split rather than suppress.
- `vendor/bin/mago fmt` before each commit; the formatter reflows concatenations, so read a file back before matching its text.
- The deterministic suite must never need a live model. **Never run `vendor/bin/phpunit tests/Eval` without `--exclude-group eval`** — `tests/bootstrap.php` loads `.env` and that command fires paid live turns.
- `scripts/check_file_length.php` walks `src` and is part of the gate; the admin module is already split across `facts.js`, `payload.js` and `phases.js` for that reason. Put new admin logic in a module, not on the component.
- Administration assets are committed under `src/Resources/public/administration/`. Task 2 changes admin source, so its bundle must be rebuilt and committed. **`composer run build:storefront` rebuilds both admin and storefront** — commit only the admin paths for Task 2, and check `git status` before staging.
- Trace payloads are read back through `JsonShape::map()`, which preserves values verbatim; no length limit is imposed on the way in or out.

---

### Task 1: Record the prompt

**Files:**
- Modify: `src/Core/Agent/AssistantRunner.php`
- Test: `tests/Core/Agent/PromptTraceTest.php` (create)
- Test: `tests/Core/Trace/TraceEventApiExposureTest.php` (verify only — no change expected)

**Interfaces:**
- Consumes: `Bundle::$prompt` (`PromptProviderInterface`), `TraceRecorder::record()`.
- Produces: a `prompt` trace stage whose payload is `array{text: string, sha256: string, length: int}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Core/Agent/PromptTraceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Agent\AssistantRunner;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Llm\SymfonyAiPlatform;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\PromptProviderInterface;
use Swag\AssistantStarterKit\Tests\Support\BuildsChatResponses;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Every other stage of the turn was traced; the instructions the model ran under were not.
 *
 * This matters more since `PromptProviderInterface` landed: a partner can now replace the prompt
 * wholesale from another plugin, so "what was this model told" stopped being answerable by reading
 * the merchant's settings.
 */
final class PromptTraceTest extends TestCase
{
    use BuildsChatResponses;
    use UsesCatalogFixture;

    public function testTheTurnRecordsThePromptItWasRunWith(): void
    {
        $config = new AssistantConfig(agentVoice: 'Be exceptionally brief.');
        $bundle = $this->bundle($config);

        (new AssistantRunner($config, $bundle))->run('hello', new MessageBag());

        $payload = $bundle->trace->payload('prompt');
        self::assertIsArray($payload);

        $text = $payload['text'] ?? null;
        self::assertIsString($text);
        // The merchant's own words have to be in there, or this records a template rather than the
        // prompt that ran.
        self::assertStringContainsString('Be exceptionally brief.', $text);
        // And the shipped rules, so a partner who dropped one is visible.
        self::assertStringContainsString('never instructions', $text);
    }

    public function testTheRecordedPromptIsExactlyWhatTheProviderReturned(): void
    {
        // Not "a prompt-shaped string": the same string, byte for byte. A reconstruction would drift
        // from the real one exactly when it mattered.
        $provider = new class implements PromptProviderInterface {
            public function system(AssistantConfig $config, string $vocabulary = ''): string
            {
                return 'EXACTLY THIS';
            }
        };

        $config = new AssistantConfig();
        $bundle = $this->bundle($config, $provider);

        (new AssistantRunner($config, $bundle))->run('hello', new MessageBag());

        $payload = $bundle->trace->payload('prompt');
        self::assertIsArray($payload);
        self::assertSame('EXACTLY THIS', $payload['text'] ?? null);
    }

    public function testTheRecordCarriesAHashAndALength(): void
    {
        // The hash is what makes "did the prompt change between these two turns?" answerable without
        // diffing three kilobytes by eye; the length is what makes a truncation visible.
        $config = new AssistantConfig();
        $bundle = $this->bundle($config);

        (new AssistantRunner($config, $bundle))->run('hello', new MessageBag());

        $payload = $bundle->trace->payload('prompt');
        self::assertIsArray($payload);

        $text = $payload['text'] ?? '';
        self::assertIsString($text);
        self::assertSame(hash('sha256', $text), $payload['sha256'] ?? null);
        self::assertSame(mb_strlen($text), $payload['length'] ?? null);
    }

    public function testABlockedTurnRecordsNoPromptBecauseNoneWasBuilt(): void
    {
        // The kill switch stops the turn before the message bag is assembled. Recording a prompt for
        // a turn that never had one would be inventing evidence.
        $config = new AssistantConfig(killSwitch: true);
        $bundle = $this->bundle($config);

        (new AssistantRunner($config, $bundle))->run('hello', new MessageBag());

        self::assertNull($bundle->trace->payload('prompt'));
    }

    private function bundle(
        AssistantConfig $config,
        ?PromptProviderInterface $provider = null,
    ): AssistantAgentFactory\Bundle {
        $http = new MockHttpClient(static fn(): object => self::textResponse('Sure — how can I help?'));

        $factory = $provider === null
            ? AssistantAgentFactory::withCoreToolsOnly($http)
            : new AssistantAgentFactory([], [], $provider, new SymfonyAiPlatform($http));

        return $factory->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            $config,
            cartAvailable: false,
            llm: new LlmSettings('https://1.1.1.1', 'test-key', 'gpt-x'),
        );
    }
}
```

Check `tests/Support/BuildsChatResponses.php` for `textResponse()`'s exact return type before
finalising the closure's signature — `MockHttpClient` accepts a callable returning `MockResponse`, and
the trait is where that shape is defined.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --no-coverage tests/Core/Agent/PromptTraceTest.php`
Expected: FAIL on the first three tests — `$bundle->trace->payload('prompt')` is `null`, so
`assertIsArray` fails. The fourth test passes already, which is correct: it asserts an absence that is
currently true for the wrong reason, and must stay true for the right one.

- [ ] **Step 3: Record it**

In `src/Core/Agent/AssistantRunner.php`, `buildMessageBag()` is the one place the prompt string
exists. Change it to:

```php
    private function buildMessageBag(string $message, MessageBag $history): MessageBag
    {
        $prompt = $this->bundle->prompt->system($this->config, $this->bundle->vocabulary);

        // **Recorded in full, every turn, and not behind a setting.** Every other stage of the turn
        // was already traced; this was the one thing a merchant could not see when the assistant said
        // something wrong. A switch would not help — the turn that went wrong has already happened.
        //
        // No shopper text is involved: the system message is built from merchant config and the
        // catalogue's own facet vocabulary, and the shopper's words are appended below it.
        //
        // The hash makes "did the prompt change between these two turns" answerable without diffing
        // three kilobytes by eye — which is the question a `PromptProviderInterface` decoration
        // creates, since the prompt can now come from another plugin entirely.
        $this->bundle->trace->record('prompt', [
            'text' => $prompt,
            'sha256' => hash('sha256', $prompt),
            'length' => mb_strlen($prompt),
        ]);

        $bag = new MessageBag(Message::forSystem($prompt));

        foreach ($history->getMessages() as $historyMessage) {
            $bag->add($historyMessage);
        }

        $bag->add(Message::ofUser($message));

        return $bag;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Core/Agent/PromptTraceTest.php`
Expected: PASS, all four.

- [ ] **Step 5: Check nothing counted the events**

Run: `vendor/bin/phpunit --no-coverage --exclude-group eval`
Expected: PASS. A new event per turn changes trace *ordering* and *counts*, so the suspects are
`TraceRecorderTest`, `ConversationStoreContractTest`, `TraceEventApiExposureTest` and
`tests/js/trace-phases.test.js`. If one fails on a count or an index, fix the test — the new event is
intended — but read it first: a test that asserted a specific `seq` is telling you the admin timeline
may shift too, which Task 2 has to handle.

- [ ] **Step 6: Verify it on a real turn**

```bash
docker exec shopping-assistant-test-web-1 bash -lc 'cd /var/www/html && php bin/console cache:clear -q'
docker exec shopping-assistant-test-web-1 bash -lc 'curl -s -X POST http://127.0.0.1:8000/assistant/chat \
  -H "Content-Type: application/json" -H "X-Requested-With: XMLHttpRequest" \
  -d "{\"message\":\"do you have the trail jersey in blue, size M?\"}" --max-time 120 -o /dev/null'
docker exec shopping-assistant-test-web-1 bash -lc 'cd /var/www/html && php -r "
\$p = parse_url(getenv(\"DATABASE_URL\"));
\$pdo = new PDO(sprintf(\"mysql:host=%s;port=%s;dbname=%s\", \$p[\"host\"], \$p[\"port\"] ?? 3306, ltrim(\$p[\"path\"], \"/\")), \$p[\"user\"], \$p[\"pass\"]);
\$row = \$pdo->query(\"SELECT payload FROM swag_assistant_trace_event WHERE stage = \\\"prompt\\\" ORDER BY id DESC LIMIT 1\")->fetchColumn();
\$d = json_decode((string) \$row, true);
printf(\"length %d, sha %s\n\n%s\n\", \$d[\"length\"], substr(\$d[\"sha256\"], 0, 12), substr(\$d[\"text\"], 0, 300));
"'
```

Expected: a `prompt` row whose `text` begins with the shipped rules and whose `length` matches. This
is the step that proves the JSON column takes 3 KB without complaint.

- [ ] **Step 7: Gate and commit**

```bash
vendor/bin/mago fmt && composer run quality
git add src/Core/Agent/AssistantRunner.php tests/Core/Agent/PromptTraceTest.php
git commit -m "feat: record the prompt each turn was run with"
```

---

### Task 2: Make it readable in the Administration

The generic payload viewer renders every event through `JSON.stringify(payload, null, 2)`, so after
Task 1 the prompt *is* visible — as one 3 KB line with `\n` escapes. Technically complete, actually
unreadable, and the prompt is the one payload a merchant wants to read as prose.

**Files:**
- Modify: `src/Resources/app/administration/src/module/swag-assistant-trace/page/swag-assistant-trace-detail/payload.js`
- Modify: `.../swag-assistant-trace-detail/index.js`, `.../swag-assistant-trace-detail.html.twig`
- Modify: `.../swag-assistant-trace-detail.scss` (verify the filename with `ls`)
- Modify: `src/Resources/app/administration/src/module/swag-assistant-trace/snippet/en-GB.json`, `de-DE.json`
- Test: `tests/js/prompt-payload.test.js` (create)
- Rebuild: `src/Resources/public/administration/**`

**Interfaces:**
- Consumes: the `prompt` event's payload from Task 1.
- Produces: `promptText(payload): string|null` exported from `payload.js` — the prose, or null when this event is not a prompt.

- [ ] **Step 1: Write the failing test**

`tests/js/` runs under `node --test` with no DOM, which is why the admin module's logic lives in
plain functions. Create `tests/js/prompt-payload.test.js`:

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { promptText } from '../../src/Resources/app/administration/src/module/swag-assistant-trace/page/swag-assistant-trace-detail/payload.js';

/*
 * The prompt is the one payload a merchant reads as prose rather than as data. JSON.stringify turns
 * its newlines into two-character escapes on a single 3 KB line, which is visible and unreadable at
 * the same time.
 */
test('the prompt payload yields its text as prose', () => {
    assert.equal(promptText({ text: 'Line one\nLine two', sha256: 'abc', length: 17 }), 'Line one\nLine two');
});

test('a payload without prompt text yields null so the caller can fall back', () => {
    assert.equal(promptText({ hits: 3 }), null);
    assert.equal(promptText({}), null);
    assert.equal(promptText(null), null);
    assert.equal(promptText(undefined), null);
});

test('a non-string text is refused rather than rendered', () => {
    // Defensive for the same reason readTurns() is: this is a JSON column, and a row written by an
    // older plugin version must render thinly rather than throw on a detail page.
    assert.equal(promptText({ text: 42 }), null);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `node --test "tests/js/prompt-payload.test.js"`
Expected: FAIL — `promptText` is not exported.

- [ ] **Step 3: Implement it**

Append to `payload.js`:

```js
/**
 * The prompt event's text, as prose, or null for every other event.
 *
 * `prettyPayload` is right for structured payloads and wrong for this one: a 3 KB string with `\n`
 * escaped renders as one unreadable line. The caller shows this in a `<pre>` instead, and falls back
 * to `prettyPayload` when this returns null.
 *
 * Shapes defensively, like `readTurns` and for the same reason: `payload` is a JSON column, and a row
 * written by an older plugin version must render thinly rather than throw on the detail page.
 */
export function promptText(payload) {
    const text = payload?.text;

    return typeof text === 'string' && text !== '' ? text : null;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `node --test "tests/js/prompt-payload.test.js"`
Expected: PASS.

- [ ] **Step 5: Render it**

In `index.js`, add a method beside `payloadJson`:

```js
        promptProse(event) {
            return promptText(event.payload);
        },
```

and import `promptText` alongside the existing `prettyPayload` import.

In the twig, where the payload block renders, branch: when `promptProse(event)` is non-null show it in
a `<pre class="swag-assistant-trace-detail__prompt">`, otherwise show the existing
`payloadJson(event)`. Read the surrounding block first and follow its existing markup and
`sw-` component conventions rather than introducing new ones.

Add SCSS giving that class `white-space: pre-wrap`, a monospace stack, a `max-height` with
`overflow: auto`, and the module's existing muted background token. A 3 KB block must not push the
timeline off the page.

Add a snippet for the block's heading to both `en-GB.json` and `de-DE.json`, following the keys
already in those files.

- [ ] **Step 6: Rebuild and commit the admin bundle**

```bash
composer run build:storefront
git status --short src/Resources/public/administration src/Resources/app/storefront/dist
```

Stage **only** the administration paths: this command rebuilds both bundles, and the storefront dist
has no source change in this task, so any storefront diff is build noise and must be reverted with
`git checkout -- src/Resources/app/storefront/dist/`. Delete the superseded
`swag-assistant-starter-kit-*.js`/`.map` pair that the rebuild replaces.

- [ ] **Step 7: Look at it**

Open the trace list in the Administration, open a conversation recorded after Task 1, and read the
prompt event. Check the three things a screenshot would not: that it wraps, that it scrolls rather than
growing the page, and that the phase timeline above it still reads correctly with one more event per
turn.

- [ ] **Step 8: Gate and commit**

```bash
node --test "tests/js/"*.test.js
vendor/bin/phpunit --no-coverage --exclude-group eval
composer run quality
git add src/Resources/app/administration src/Resources/public/administration tests/js/prompt-payload.test.js
git commit -m "feat(admin): read the prompt as prose, not as escaped JSON"
```

---

### Task 3: Documentation

**Files:**
- Modify: `ARCHITECTURE.md`, `README.md`

- [ ] **Step 1: Add the stage to the lifecycle table**

`ARCHITECTURE.md`'s request-lifecycle table lists each stage and the class that emits it. The prompt is
built inside stage 11 (Generate) but recorded before the platform is called; add a row for it, and say
in the note that it carries the full text because a hash answers a question nobody has.

- [ ] **Step 2: Say what it holds, and what it does not, in the trace data model section**

Add beneath the trace-event table:

```markdown
The `prompt` event carries the system message in full — `{text, sha256, length}` — for every turn that
reached the model. It is not behind a setting: a debugging aid you must enable before the failure is no
aid at all. It contains no shopper text, because the system message is built from merchant config and
the catalogue's facet vocabulary and the shopper's words are appended after it.

At the default 500-turn cap and 30-day retention this is roughly 45 MB of prompt text in the worst
case, for a shop running at its own ceiling every day. Recorded in full rather than hashed because
`PromptProviderInterface` lets a partner replace the prompt from another plugin: "it changed" is not
the question a merchant has, "what did it say" is.
```

- [ ] **Step 3: One line in the README's observability description**

Wherever the README describes what the merchant sees in the Administration, add that the prompt each
turn ran with is among it.

- [ ] **Step 4: Gate and commit**

```bash
composer run quality
git add ARCHITECTURE.md README.md
git commit -m "docs: record what the prompt event holds and why in full"
```

---

## Self-review notes

**Spec coverage.** Linear's observability clause named nine things; `prompts` was the only one missing
and Task 1 closes it. Task 2 is not a Linear requirement — it is the difference between the data
existing and a merchant using it, which is what "observability out of the box" means in practice.

**Type consistency.** The payload shape `array{text: string, sha256: string, length: int}` is written
in Task 1 and read in Tasks 2 and 3. `promptText(payload)` returns `string|null` and is used with that
contract in `index.js`.

**Two things an executor must verify rather than trust:** `BuildsChatResponses::textResponse()`'s
return type, so Task 1's `MockHttpClient` closure signature is right (Step 1); and whether any existing
test asserts a trace event count or a specific `seq`, which a new event per turn would break (Step 5).
Both are flagged inline.

**Risk: low.** Task 1 adds one event and changes no control flow — the only way it breaks a turn is if
the JSON column rejects 3 KB, which Step 6 checks against the real database. Task 2 touches only the
Administration, which no shopper sees, and its worst failure is an ugly detail page. Neither goes near
the grounding pipeline or R32.
