import path from 'node:path';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

/** @returns {import('vite').ServerOptions} */
function devServer() {
    const watch = {
        ignored: ['**/storage/framework/views/**'],
    };
    if (process.env.VITE_DEV_SERVER_USE_POLLING === 'true') {
        watch.usePolling = true;
    }

    /** @type {import('vite').ServerOptions} */
    const server = { watch };

    const host = process.env.VITE_DEV_SERVER_HOST;
    if (host) {
        server.host = host;
    }

    const port = process.env.VITE_DEV_SERVER_PORT;
    if (port) {
        server.port = Number(port);
        server.strictPort = true;
    }

    const hmrHost = process.env.VITE_DEV_SERVER_HMR_HOST;
    const hmrPort = process.env.VITE_DEV_SERVER_HMR_PORT;
    if (hmrHost || hmrPort) {
        server.hmr = {};
        if (hmrHost) {
            server.hmr.host = hmrHost;
        }
        if (hmrPort) {
            server.hmr.port = Number(hmrPort);
        }
    }

    return server;
}

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            // Full page reload when PHP / Blade / routes change (Vue/JS use Vite HMR).
            refresh: [
                {
                    paths: [
                        'app/**',
                        'bootstrap/**',
                        'config/**',
                        'database/**/*.php',
                        'lang/**',
                        'resources/lang/**',
                        'resources/views/**',
                        'routes/**',
                    ],
                },
            ],
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: [
            // Actual dirs are Stores/Composables; lowercase imports work on macOS but fail in Linux Docker.
            { find: '@/stores', replacement: path.resolve(__dirname, 'resources/js/Stores') },
            { find: '@/composables', replacement: path.resolve(__dirname, 'resources/js/Composables') },
            { find: '@', replacement: path.resolve(__dirname, 'resources/js') },
        ],
    },
    server: devServer(),
});
