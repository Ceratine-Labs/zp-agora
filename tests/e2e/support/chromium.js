/**
 * Which chromium the suite runs.
 *
 * Playwright wants the exact build it shipped with. When that build is present
 * this file gets out of the way entirely — returning undefined lets Playwright
 * resolve the browser itself, which is what picks the headless shell rather
 * than full Chrome and is faster.
 *
 * The fallback exists for the case where it is NOT present: a fresh checkout on
 * a metered or solar-powered connection should be able to run the suite against
 * a build already on the machine instead of downloading ~380 MB before it can
 * do anything. That fallback is a real compromise — a browser a couple of
 * releases behind can differ on new CSS and new APIs, which is right for a
 * smoke suite and wrong for chasing a rendering bug — so it announces itself
 * once rather than applying silently.
 *
 * To remove it:
 *
 *     npx playwright install chromium
 */
import { existsSync, readdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { createRequire } from 'node:module';
import { homedir } from 'node:os';
import { join } from 'node:path';

const require = createRequire(import.meta.url);

const CACHE = process.env.PLAYWRIGHT_BROWSERS_PATH || join(homedir(), '.cache', 'ms-playwright');

function executable(dir) {
    for (const candidate of [
        join(dir, 'chrome-linux64', 'chrome'),
        join(dir, 'chrome-linux', 'chrome'),
    ]) {
        if (existsSync(candidate)) return candidate;
    }
    return null;
}

/** The revision this Playwright expects, read from its own manifest. */
function expectedRevision() {
    try {
        // Resolved via package.json, not by requiring browsers.json directly:
        // playwright-core's exports map does not expose it, so the direct
        // require fails and the fallback would then apply forever, even once
        // the matched build was installed.
        const pkg = require.resolve('playwright-core/package.json');
        const manifest = require(join(dirname(pkg), 'browsers.json'));

        return manifest.browsers.find((b) => b.name === 'chromium')?.revision ?? null;
    } catch {
        return null;
    }
}

/**
 * undefined  → Playwright's matched build is installed; let it choose.
 * a path     → the matched build is missing; use the newest one cached.
 */
export function cachedChromium() {
    const expected = expectedRevision();

    if (expected && executable(join(CACHE, `chromium-${expected}`))) {
        return undefined;
    }

    if (!existsSync(CACHE)) return undefined;

    const builds = readdirSync(CACHE)
        .filter((name) => /^chromium-\d+$/.test(name))
        .map((name) => ({ name, revision: Number(name.split('-')[1]) }))
        .sort((a, b) => b.revision - a.revision);

    for (const build of builds) {
        const path = executable(join(CACHE, build.name));
        if (path) {
            // Said once, not per test. A silent substitution is how a rendering
            // difference gets blamed on the code.
            console.warn(
                `\n  Playwright wants chromium ${expected}; using cached ${build.revision} instead.` +
                '\n  Run `npx playwright install chromium` for the matched build.\n'
            );
            return path;
        }
    }

    // Nothing cached either — let Playwright behave normally, including asking
    // you to install.
    return undefined;
}
