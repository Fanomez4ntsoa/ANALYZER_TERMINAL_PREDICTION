import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // Breeze (profil, authentification)
                'resources/css/app.css',
                'resources/js/app.js',
                // Terminal (docs/design-system.md)
                'resources/css/terminal.css',
                'resources/js/terminal.js',
            ],
            refresh: true,
        }),
    ],
});
