import { defineConfig, devices } from '@playwright/test';
import { cachedChromium } from './tests/e2e/support/chromium.js';
import { STATE_FILE } from './tests/e2e/support/fixtures.js';
import 'dotenv/config';

/**
 * Browser tests for Agora.
 *
 * Two things shape this config more than anything else:
 *
 *  - **The suite runs against the customer's production database.** Specs are
 *    read-only. The only rows any of them touch belong to the TEST- fixtures
 *    created by E2eFixtureSeeder, and even those are only read. A spec that
 *    needs to write must say so in review, use branch 999, and clean up.
 *  - **Targeted runs only.** Ryan is on solar and usually working on the
 *    machine at the same time, so this ships with ONE worker, no retries and
 *    no trace-on-by-default. Run the spec you touched:
 *
 *        npx playwright test tests/e2e/login.spec.js --project=desktop
 *
 *    `--project=mobile` is the 375x812 pass. Running the whole suite in both
 *    projects is his call, not a default.
 */
const baseURL = process.env.AGORA_BASE_URL || 'http://127.0.0.1:8123';

export default defineConfig({
    testDir: './tests/e2e',
    // Serial. Parallel browsers against one PHP dev server and a database on
    // the other side of the internet is a way to time out, not go faster.
    workers: 1,
    fullyParallel: false,
    retries: 0,
    reporter: process.env.CI ? 'line' : [['list']],
    timeout: 30_000,
    expect: { timeout: 7_000 },

    use: {
        baseURL,
        // Only on failure — a trace per test is disk and time nobody asked for.
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
        launchOptions: { executablePath: cachedChromium() },
    },

    projects: [
        {
            // Signs in once and saves the session. Sign-in is rate limited to
            // five attempts per address per five minutes — a protection worth
            // keeping — so a suite that signed in per test tripped it on any
            // file with more than five tests, intermittently.
            name: 'setup',
            testMatch: /.*\.setup\.js/,
        },
        {
            name: 'desktop',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1440, height: 900 },
                storageState: STATE_FILE,
            },
            dependencies: ['setup'],
        },
        {
            name: 'mobile',
            // A real phone viewport, because "clean over capable" on mobile is
            // a stated requirement and a shell that only works at 1440 fails it.
            use: {
                ...devices['Pixel 7'],
                viewport: { width: 375, height: 812 },
                storageState: STATE_FILE,
            },
            dependencies: ['setup'],
        },
    ],

    webServer: {
        // Serves the built assets. `npm run build` first if you have changed
        // anything under resources/ — this deliberately does not run Vite, so a
        // stale build is visible rather than papered over.
        command: 'php artisan serve --host=127.0.0.1 --port=8123',
        url: baseURL + '/login',
        reuseExistingServer: true,
        timeout: 60_000,
        stdout: 'ignore',
        stderr: 'pipe',
    },
});
