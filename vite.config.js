import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    css: {
        preprocessorOptions: {
            scss: {
                /*
                 | Bootstrap 5's own SCSS still uses @import and the legacy
                 | colour functions Dart Sass has deprecated ahead of Sass 3.
                 | They are warnings, not errors, and they are not ours to fix
                 | — but 315 of them per build drown out a warning from OUR
                 | stylesheets, which is the one that would matter.
                 |
                 | quietDeps silences node_modules ONLY. A deprecation in
                 | resources/scss still prints. This is not "silence the
                 | check": it is silencing a dependency so our own check stays
                 | audible. Remove it when Bootstrap ships the @use rewrite.
                 */
                quietDeps: true,
            },
        },
    },
    plugins: [
        laravel({
            input: ['resources/scss/app.scss', 'resources/js/app.js'],
            refresh: [
                'resources/views/**',
                'Modules/*/Resources/views/**',
                'routes/**',
                'Modules/*/Routes/**',
            ],
        }),
    ],
});
