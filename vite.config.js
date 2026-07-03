import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/dashboard.css',
                'resources/js/dashboard.js',
                'resources/css/role-access.css',
                'resources/js/role-access.js',
                'resources/css/stock-management.css',
                'resources/js/stock-management.js',
                // Make sure these match the exact folder names!
                'resources/css/products.css', 
                'resources/js/products.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});


