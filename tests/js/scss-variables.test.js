import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';

/*
 * Every `$swag-assistant-*` a stylesheet uses must be one a stylesheet defines.
 *
 * This exists because of a real deploy. `_card.scss` used `$swag-assistant-border`, which does not
 * exist — the token is called `$swag-assistant-hairline` — and NOTHING local said so: `composer
 * build` runs webpack over the plugin's JavaScript and never compiles the SCSS, because the theme
 * compiler that does needs a Shopware installation and a theme. The failure surfaced on the shop,
 * as `theme:compile` refusing the whole storefront theme:
 *
 *     Unable to compile the theme "Storefront": Undefined variable $swag-assistant-border
 *
 * That is not a cosmetic failure. A theme that will not compile is a storefront that does not get
 * its stylesheet, so one undefined variable in one component takes the entire shop's CSS with it.
 *
 * A text check rather than a compile: it needs no Shopware, runs in milliseconds, and catches the
 * only mistake in this class anyone actually makes — a typo or a half-remembered token name. It
 * deliberately does NOT try to validate values, nesting or anything else the real compiler owns.
 */

const SCSS_ROOT = 'src/Resources/app/storefront/src/scss';
const VARIABLE = /\$swag-assistant-[a-z0-9-]+/g;
const DEFINITION = /^\s*(\$swag-assistant-[a-z0-9-]+)\s*:/gm;

function scssFiles(dir) {
    return readdirSync(dir).flatMap((entry) => {
        const path = join(dir, entry);

        if (statSync(path).isDirectory()) {
            return scssFiles(path);
        }

        return path.endsWith('.scss') ? [path] : [];
    });
}

test('every assistant SCSS variable used is also defined', () => {
    const files = scssFiles(SCSS_ROOT);
    assert.ok(files.length > 0, `no stylesheets found under ${SCSS_ROOT}`);

    const sources = files.map((path) => [path, readFileSync(path, 'utf8')]);

    const defined = new Set(
        sources.flatMap(([, source]) => [...source.matchAll(DEFINITION)].map((match) => match[1])),
    );

    const undefinedUses = sources.flatMap(([path, source]) =>
        [...new Set(source.match(VARIABLE) ?? [])]
            .filter((name) => !defined.has(name))
            .map((name) => `${path}: ${name}`),
    );

    assert.deepEqual(
        undefinedUses,
        [],
        `undefined variables would fail theme:compile and leave the storefront with no stylesheet:\n${undefinedUses.join('\n')}`,
    );
});
