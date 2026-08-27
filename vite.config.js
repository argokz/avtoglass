import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Обе гарнитуры выбраны из-за полной кириллицы: Instrument Sans её
            // не покрывает, и на русском тексте шрифт подменялся системным.
            // Кириллица обязательна и её надо просить явно: по умолчанию плагин
            // тянет только латинский сабсет, и русский текст молча уезжает
            // в системный шрифт.
            fonts: [
                bunny('Manrope', { weights: [600, 700, 800], subsets: ['latin', 'cyrillic'] }),
                bunny('Inter', { weights: [400, 500, 600], subsets: ['latin', 'cyrillic'] }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
