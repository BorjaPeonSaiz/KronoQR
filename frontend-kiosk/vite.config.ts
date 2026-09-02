import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { readFileSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

// La version que declara el latido (`app_version`) sale del `package.json`, no
// de una constante duplicada: dos sitios donde escribir la version es un sitio
// donde equivocarse, y el campo sirve justamente para saber que quioscos no se
// han actualizado (RF-KI-07, §10.5).
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

// Ruta bajo la que se sirve el quiosco. En produccion Nginx lo publica en
// `/kiosk/` y la imagen de entrega construye con KRONOQR_BASE=/kiosk/
// (infra/docker/nginx/Dockerfile). En desarrollo y en las pruebas E2E vale `/`.
//
// AQUI IMPORTA MAS QUE EN LAS OTRAS DOS SPA porque es una PWA: `start_url`,
// `scope` y el respaldo de navegacion del service worker tienen que salir de
// ESTE mismo valor. Si se dejaran fijos en `/`, la tablet instalada abriria la
// aplicacion en la raiz —que sirve la API, no el quiosco— y el respaldo sin red
// no encontraria su index.html precacheado: el fallo aparecería el primer dia
// que se cayera el wifi, que es exactamente cuando el modo offline existe.
const base = process.env['KRONOQR_BASE'] ?? '/'

export default defineConfig({
  base,
  plugins: [
    vue(),
    tailwindcss(),
    VitePWA({
      // 'prompt' y no 'autoUpdate' a proposito: una actualizacion que se
      // aplica sola puede recargar el quiosco en mitad del cambio de turno de
      // las 06:00. La ventana controlada de actualizacion es RF-KI-07 (tarea
      // 3.12); hasta entonces, nada se actualiza sin que alguien lo acepte.
      registerType: 'prompt',
      // El registro lo hace `src/sw/registerServiceWorker.ts`, para que la
      // decision de cuando aplicar una version nueva viva en codigo nuestro y no
      // en un guion inyectado.
      injectRegister: null,
      manifest: {
        name: 'KronoQR',
        short_name: 'KronoQR',
        description: 'Registro horario por QR',
        start_url: base,
        scope: base,
        // 'fullscreen' y no 'standalone': el quiosco ocupa la pantalla entera,
        // sin barra de estado ni de navegacion (RF-KI-01). Un empleado con prisa
        // no debe poder salirse de la aplicacion por rozar la barra de arriba.
        display: 'fullscreen',
        display_override: ['fullscreen', 'standalone'],
        orientation: 'landscape',
        background_color: '#0f172a',
        theme_color: '#0f172a',
        lang: 'es',
        // Sin iconos: los aporta la marca blanca (RF-PD-08, tarea 5.8), que es
        // configuracion y no codigo (CLAUDE.md, regla dura 13).
        icons: [],
      },
      workbox: {
        // Las fuentes autoalojadas (@kronoqr/web-kit/fonts.css) se sirven un
        // fichero .woff2 por subconjunto Unicode, y @fontsource genera todos
        // los subconjuntos del tipo (latin, latin-ext, cyrillic, cyrillic-ext,
        // greek, greek-ext, vietnamese, devanagari) aunque KronoQR solo ofrezca
        // es/en (latin) y ca/ro (latin-ext) — vease docs/06-guia-visual.md §3.
        // Precachear TODOS inflaria la instalacion sin uso: el navegador nunca
        // pide el subconjunto devanagari si `lang` nunca vale "hi". El glob
        // `*-latin-*.woff2` cubre latin Y latin-ext (el nombre de fichero de
        // latin-ext contiene "-latin-" como subcadena), que es justo lo que
        // hace falta para operar sin red en los idiomas soportados; el arabe
        // (doc 01 §6.6) no lo cubre ninguno de los dos y usa la pila de
        // respaldo del sistema, que no necesita precacheo.
        globPatterns: ['**/*.{js,css,html,svg}', '**/*-latin-*.woff2'],
        // El *app shell* completo, decodificador incluido, cabe de sobra. El
        // techo por defecto de Workbox (2 MiB) dejaria fuera el trozo de ZXing y
        // el quiosco arrancaria sin poder escanear precisamente cuando no hay
        // red, que es cuando el precacheo importa.
        maximumFileSizeToCacheInBytes: 6 * 1024 * 1024,
        // Con el prefijo delante: el manifiesto de precacheo guarda las rutas
        // ya prefijadas por `base`, y un 'index.html' pelado no casaria con
        // '/kiosk/index.html' en produccion.
        navigateFallback: `${base}index.html`,
        // La API NUNCA se cachea: un fichaje servido desde la cache seria un
        // registro legal inventado.
        navigateFallbackDenylist: [/^\/api\//],
        cleanupOutdatedCaches: true,
      },
      devOptions: { enabled: false },
    }),
  ],
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
    port: 5173,
    strictPort: true,
    // El cliente HTTP llama a /api/v1 en el MISMO origen (sin CORS, ADR-017);
    // en desarrollo ese origen es este servidor de Vite, que reenvia al Nginx
    // del entorno. secure:false por el certificado autofirmado de desarrollo.
    proxy: {
      '/api': { target: 'https://localhost', changeOrigin: true, secure: false },
    },
  },
})
