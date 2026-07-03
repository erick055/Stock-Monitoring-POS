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
                'resources/css/products.css', 
                'resources/js/products.js',
                'resources/css/analytics.css',
                'resources/js/analytics.js',
                'resources/css/low-stocks.css',
                'resources/js/low-stocks.js',
                'resources/css/dead-stock.css',
                'resources/js/dead-stock.js',
                'resources/css/suppliers.css',
                'resources/js/suppliers.js',
                
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
