import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
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
