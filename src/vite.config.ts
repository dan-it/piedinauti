import vue from '@vitejs/plugin-vue';
import autoprefixer from 'autoprefixer';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import tailwindcss from 'tailwindcss';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: [
            { find: '@', replacement: path.resolve(__dirname, './resources/js') },
            // Build only the icons listed in resources/js/lib/lucide.ts (see that file).
            // The regex matches the bare package name, so the per-icon deep imports still resolve.
            { find: /^lucide-vue-next$/, replacement: path.resolve(__dirname, './resources/js/lib/lucide.ts') },
        ],
    },
    server: {
        // The dev server runs in a container; the browser reaches it through this host name
        // (written to public/hot by the Laravel plugin). Override with VITE_HMR_HOST.
        host: '0.0.0.0',
        hmr: { host: process.env.VITE_HMR_HOST ?? 'localhost' },
        watch: {
            // Do not watch PHP dependencies, logs and build output: they hold thousands of
            // folders and are not part of the frontend. Watching them exhausts the system's
            // limit on file watchers (ENOSPC) and makes the dev server crash.
            ignored: ['**/vendor/**', '**/storage/**', '**/bootstrap/cache/**', '**/public/build/**', '**/.git/**', '**/node_modules/**'],
        },
    },
    build: {
        // Skipping the gzip size report saves time on slow machines.
        reportCompressedSize: false,
    },
    css: {
        postcss: {
            plugins: [tailwindcss, autoprefixer],
        },
    },
});
