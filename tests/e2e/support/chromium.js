/**
 * Which chromium the suite runs.
 *
 * Playwright wants the exact build it shipped with and downloads it on first
 * use — about 380 MB. Ryan's machine is on solar and already has several
 * earlier builds cached from other projects, so this prefers the matched build
 * when it is there and falls back to the newest cached one when it is not.
 *
 * The fallback is a real compromise, not a free lunch: a browser two releases
 * behind can differ on new CSS and new APIs. It is right for a smoke suite and
 * wrong for chasing a rendering bug. To get the matched build:
 *
 *     npx playwright install chromium
 *
 * Once that has run, this file finds it and the fallback stops applying. If
 * nothing is cached at all it returns undefined and Playwright behaves
 * normally — including asking you to install.
 */
import { existsSync, readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';

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

export function cachedChromium() {
    if (!existsSync(CACHE)) return undefined;

    const builds = readdirSync(CACHE)
        .filter((name) => /^chromium-\d+$/.test(name))
        .map((name) => ({ name, revision: Number(name.split('-')[1]) }))
        .sort((a, b) => b.revision - a.revision);

    for (const build of builds) {
        const path = executable(join(CACHE, build.name));
        if (path) return path;
    }

    return undefined;
}

/** True when Playwright's own matched build is present, so no fallback applies. */
export function usingMatchedBuild() {
    const chosen = cachedChromium();
    return chosen === undefined || !process.env.AGORA_E2E_BROWSER_WARN;
}
