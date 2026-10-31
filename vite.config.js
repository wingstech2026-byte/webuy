import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    // When the app is opened from a phone on the Wi-Fi, the dev server must be reachable on the
    // computer's network address too. Set VITE_DEV_HOST to that address (e.g. 192.168.1.20) in .env.
    const devHost = env.VITE_DEV_HOST || 'localhost';

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                // Reload the page when a Blade view, route or PHP file changes (like hot reload).
                refresh: true,
            }),
            tailwindcss(),
        ],
        server: {
            host: devHost === 'localhost' ? 'localhost' : '0.0.0.0',
            port: 5173,
            strictPort: true,
            origin: `http://${devHost}:5173`,
            cors: true,
            hmr: { host: devHost },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
