import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/guest.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        // `make dev` runs this server in deployme-vite behind the shared edge,
        // so the browser loads modules and HMR from vite.deployme.localhost.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: 'https://vite.deployme.localhost',
        allowedHosts: ['vite.deployme.localhost'],
        cors: { origin: 'https://deployme.localhost' },
        hmr: { host: 'vite.deployme.localhost', protocol: 'wss', clientPort: 443 },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
