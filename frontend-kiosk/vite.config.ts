import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { readFileSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

// La version que declara el latido (`app_version`) sale del fichero `VERSION`
// de la raiz del repositorio (DC8), que es el mismo que fija la etiqueta del
// release, las imagenes y el instalador: un solo sitio donde escribirla. El
// `package.json` del quiosco dice 0.0.0 a proposito y NO sirve: con el, la
// columna de version del panel enseñaba «0.0.0» en toda tablet y dejaba de
// servir para lo que existe (saber que quioscos no se han actualizado,
// RF-KI-07, §10.5).
//
// `npm run dev` y los E2E tambien leen VERSION. Solo el build de PRODUCCION
// se niega a seguir sin ella: una imagen con `app_version` inventada es peor
// que una imagen que no se construye (la imagen de Nginx copia VERSION
// a proposito, `infra/docker/nginx/Dockerfile`).
const SEMVER = /^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.+-]+)?$/

//
// KRONOQR_APP_VERSION (opcional) GANA sobre VERSION. La fija la imagen de Nginx
// (`ARG APP_VERSION`, que `make build-ci-images` rellena con el mismo valor que
// da a la imagen de PHP): en la CI con version sintetica (`X.Y.(Z+1)-ci`) el
// servidor anuncia esa version en `minimum_app_version` y la PWA tiene que
// declarar la misma, o toda tablet saldria desfasada. En una release coincide
// con VERSION. Si viene y no es semver valido, el build se niega: nunca se cae
// en silencio a otra version.
const APP_VERSION_ENV = 'KRONOQR_APP_VERSION'

function readAppVersion(mode: string): string {
  const forced = process.env[APP_VERSION_ENV]?.trim() ?? ''
  if (forced !== '') {
    if (!SEMVER.test(forced)) {
      throw new Error(`${APP_VERSION_ENV} no contiene una version valida: «${forced}»`)
    }
    return forced
  }
  try {
    const raw = readFileSync(fileURLToPath(new URL('../VERSION', import.meta.url)), 'utf8')
    const version = raw.split('\n', 1)[0]?.trim() ?? ''
    if (SEMVER.test(version)) {
      return version
    }
    throw new Error(`VERSION no contiene una version valida: «${version}»`)
  } catch (error) {
    if (mode === 'production') {
      throw new Error(
        `No se puede leer VERSION para app_version: ${error instanceof Error ? error.message : String(error)}`,
        { cause: error },
      )
    }
    return '0.0.0-dev'
  }
}

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

// Cabeceras de seguridad de `vite preview` (PIN-04). Las pone
// `playwright.config.ts`, que las lee del snippet REAL de Nginx
// (`tests/e2e/support/securityHeaders.ts`) y las pasa por esta variable: asi
// los E2E corren bajo la misma CSP que la tablet instalada, y este fichero no
// depende de `infra/` (la imagen de Nginx construye el quiosco con el).
// Sin la variable -`npm run preview` a mano-, no hay cabeceras; con ella mal
// formada, se para: nunca «sin cabeceras» en silencio dentro de un E2E.
const PREVIEW_HEADERS_ENV = 'KRONOQR_PREVIEW_SECURITY_HEADERS'

function previewSecurityHeaders(): Record<string, string> | undefined {
  const raw = process.env[PREVIEW_HEADERS_ENV]
  if (raw === undefined) {
    return undefined
  }
  const parsed: unknown = JSON.parse(raw)
  if (
    typeof parsed !== 'object' ||
    parsed === null ||
    Array.isArray(parsed) ||
    !Object.values(parsed).every((value) => typeof value === 'string') ||
    !('Content-Security-Policy' in parsed)
  ) {
    throw new Error(`${PREVIEW_HEADERS_ENV} no es un mapa de cabeceras con Content-Security-Policy`)
  }
  return parsed as Record<string, string>
}

const previewHeaders = previewSecurityHeaders()

export default defineConfig(({ mode }) => ({
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
        // Iconos de la marca del FABRICANTE (PR7), sin nombre ni marca de cliente
        // (regla dura 13): Android solo ofrece «Instalar» con PNG de 192 y 512.
        // Se generan con `scripts/generate-icons.mjs`. Relativos al manifiesto,
        // asi que siguen a `base` (`/kiosk/` en produccion). Que el icono
        // instalado siga la marca blanca de un cliente (RF-PD-08) no se resuelve
        // aqui: el manifiesto es estatico.
        icons: [
          { src: 'icons/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
          { src: 'icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
          {
            src: 'icons/icon-maskable-512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable',
          },
        ],
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
        globPatterns: ['**/*.{js,css,html,svg,png}', '**/*-latin-*.woff2'],
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
        // UNICA EXCEPCION a "la API nunca se cachea", y con motivo (RF-PD-08,
        // tarea 5.8): la marca no es un fichaje, es cosmetica. Sin cachearla,
        // un quiosco sin red arrancaria con el nombre y el logotipo del
        // producto en vez de los del cliente (RF-KI-03) hasta que volviera a
        // haber conexion. El nombre y el color de acento ya persisten en
        // `localStorage` (ver `shared/branding/useBranding.ts`); lo unico que
        // depende de esta cache es el LOGOTIPO, porque sus bytes no caben ahi.
        runtimeCaching: [
          {
            // NetworkFirst con techo corto: si hay red, la marca vigente; si
            // no contesta en 3 s, la ultima que se pudo guardar. Nunca un
            // fichaje, asi que un dato de unos minutos de retraso no importa.
            urlPattern: /\/api\/v1\/branding$/,
            handler: 'NetworkFirst',
            options: {
              cacheName: 'kronoqr-branding',
              networkTimeoutSeconds: 3,
              cacheableResponse: { statuses: [200] },
            },
          },
          {
            // CacheFirst es seguro aqui PORQUE la URL lleva la huella del
            // contenido en `v` (ver el contrato): un logotipo nuevo es una URL
            // nueva, nunca sirve uno viejo bajo un nombre nuevo.
            urlPattern: /\/api\/v1\/branding\/logo(\?.*)?$/,
            handler: 'CacheFirst',
            options: {
              cacheName: 'kronoqr-branding-logo',
              cacheableResponse: { statuses: [200] },
              expiration: { maxEntries: 4, maxAgeSeconds: 30 * 24 * 60 * 60 },
            },
          },
        ],
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
    __APP_VERSION__: JSON.stringify(readAppVersion(mode)),
    // Gancho de pruebas del guardian de actualizacion (RF-KI-07, tarea 3.12,
    // decision 16): `false` SOLO en `mode: 'production'` -el build real que
    // se instala en la tablet (`npm run build`, sin `--mode`)-, para que
    // `src/sw/testHooks.ts` quede como codigo muerto y el minificador lo
    // elimine del todo. El E2E (`playwright.config.ts`) construye con
    // `--mode test` a proposito, para que el gancho SI este presente sin
    // tocar el build de produccion.
    __KRONOQR_TEST_HOOKS__: JSON.stringify(mode !== 'production'),
  },
  build: {
    target: 'es2022',
    sourcemap: false,
  },
  preview: previewHeaders === undefined ? {} : { headers: previewHeaders },
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
}))
