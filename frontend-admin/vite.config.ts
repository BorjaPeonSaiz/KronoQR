import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { readFileSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'

// La version viene del package.json en tiempo de compilacion, como en el
// quiosco: acompaña a cada error capturado para saber en que version aparecio.
const packageJson: unknown = JSON.parse(
  readFileSync(fileURLToPath(new URL('./package.json', import.meta.url)), 'utf8'),
)
const appVersion =
  typeof packageJson === 'object' &&
  packageJson !== null &&
  'version' in packageJson &&
  typeof packageJson.version === 'string'
    ? packageJson.version
    : '0.0.0'

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  // Banderas de compilacion de vue-i18n: sin API legacy ni instalacion global
  // el compilador de mensajes se queda fuera del paquete de produccion.
  define: {
    __VUE_I18N_FULL_INSTALL__: 'false',
    __VUE_I18N_LEGACY_API__: 'false',
    __INTLIFY_PROD_DEVTOOLS__: 'false',
    __APP_VERSION__: JSON.stringify(appVersion),
  },
  build: {
    target: 'es2022',
    sourcemap: false,
  },
  server: {
    host: true,
    // 5174 y no 5173: las tres SPA se arrancan a la vez desde el host
    // (kiosk 5173, admin 5174, portal 5175). Dentro del contenedor node-* el
    // entrypoint fuerza --port 5173 y este valor no aplica.
    port: 5174,
    // El cliente HTTP llama a /api/v1 en el MISMO origen (sin CORS, ADR-017);
    // en desarrollo ese origen es este servidor de Vite, que reenvia al Nginx
    // del entorno. secure:false por el certificado autofirmado de desarrollo.
    proxy: {
      '/api': { target: 'https://localhost', changeOrigin: true, secure: false },
    },
    strictPort: true,
  },
})
