import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// No web fonts from a CDN: the shop runs offline. Inter and the icon font are in public/fonts.
export default defineConfig(({ command }) => ({
    // The dev server serves the CSS, so its url('/fonts/...') resolve against Vite: let it serve public/.
    // Not on build, or Vite would copy all of public/ into public/build.
    publicDir: command === 'serve' ? 'public' : false,
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
}));
