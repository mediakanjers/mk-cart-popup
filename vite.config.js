import { defineConfig } from 'vite';
import { resolve }      from 'path';

/**
 * Vite build config for mk-cart-popup.
 *
 * Source files live in src/admin/builder/.
 * Output goes to admin/assets/ — the same paths WordPress already enqueues.
 * No PHP changes needed: just run `npm run build` after editing src files.
 *
 * Commands:
 *   npm run build   → minified one-time build (use before releasing)
 *   npm run dev     → watch mode, rebuilds on every save (use while developing)
 */
export default defineConfig( {
    build: {
        // Output next to the existing CSS files WordPress already enqueues.
        outDir    : 'admin/assets',
        emptyOutDir: false,         // don't wipe builder.css, settings.css, etc.

        lib: {
            entry   : resolve( __dirname, 'src/admin/builder/index.js' ),
            name    : 'MKCPBuilder',
            formats : [ 'iife' ],           // IIFE = works as a plain <script> tag
            fileName: () => 'builder.js',   // always outputs admin/assets/builder.js
        },

        minify       : 'terser',
        sourcemap    : false,       // set to true locally if you need to debug minified output
        terserOptions: {
            compress: {
                drop_debugger: true,
                drop_console : false,   // keep console.warn / console.error
            },
        },
    },
} );
