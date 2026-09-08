// Marca blanca del quiosco (RF-PD-08, tarea 5.8, @RF-KI-03).
//
// EL BACKEND NO PARTICIPA. `stubBrandingApi` (tests/e2e/support/kiosk.ts) sirve
// tanto la marca del producto (por defecto, via `stubKioskApi`) como la de un
// cliente de ejemplo («Hotel Marina», el mismo del contrato). La validacion del
// logotipo al guardar es cosa del backend (`BrandingEndpointTest`); aqui solo se
// prueba que el quiosco PINTA lo que el servidor le manda, y que no deja de
// pintarlo cuando el servidor deja de contestar.
//
// COMO SE SIMULA "SIN RED". Igual que `offline.spec.ts`: no se toca
// `navigator.onLine` (se queda en `true`, como en un navegador real con la
// interfaz levantada), se ABORTA la peticion en el borde con
// `route.abort('failed')`. Cubre con fiabilidad el `fetch()` que hace NUESTRO
// codigo (`shared/api/client.ts`), que es de donde sale el nombre y el color
// (persisten en `localStorage`, sin depender del *service worker*). Lo que
// NO cubre con fiabilidad -y por que- esta al final del fichero.

import { expect, test } from '@playwright/test'
import { stubBrandingApi, stubKioskApi, stubScanApi } from './support/kiosk'

test.beforeEach(async ({ page }) => {
  // Sin esto la camara (que decodifica en continuo) dispararia fichajes
  // contra un `/api/v1/scan` sin doble, ensuciando la traza de la prueba con
  // fallos de red que no tienen nada que ver con la marca.
  await stubScanApi(page, { outcome: 'offline' })
})

test(
  'pinta el nombre, el logotipo y el color de un cliente (RF-PD-08)',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubKioskApi(page)
    await stubBrandingApi(page, { variant: 'hotel-marina' })

    await page.goto('/')

    const logo = page.getByTestId('brand-logo')
    await expect(logo).toBeVisible()
    await expect(logo).toHaveAttribute('alt', 'Hotel Marina')
    // Sin nombre duplicado al lado del logotipo (doc común: nunca los dos con
    // el mismo significado para el lector de pantalla).
    await expect(page.getByTestId('brand-name')).toHaveCount(0)

    await expect.poll(() => page.title()).toBe('Hotel Marina')
    await expect(page.locator('html')).toHaveAttribute('data-kq-branded', 'accent')
  },
)

test(
  'la confirmacion del fichaje TAMBIEN lleva la marca del cliente, no solo la cabecera (RF-PD-08)',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    // Bloqueante de revision de codigo: `ScanConfirmationPanel` no recibia la
    // prop `branding` desde `ScanView.vue`, asi que un fichaje enseñaba
    // «KronoQR» aunque el hotel tuviera marca propia.
    await stubKioskApi(page)
    await stubBrandingApi(page, { variant: 'hotel-marina' })

    await page.goto('/')
    await expect(page.getByTestId('brand-logo')).toBeVisible() // la marca ya llego.

    // El `beforeEach` deja `/api/v1/scan` sin red: la camara, que decodifica
    // en continuo sobre el fixture con QR, termina encolando un fichaje local
    // y enseñando la confirmacion «pendiente».
    await expect(page.getByTestId('scan-confirmation')).toBeVisible()
    await expect(page.getByTestId('confirmation-brand-logo')).toBeVisible()
    await expect(page.getByTestId('confirmation-brand-name')).toHaveCount(0)
  },
)

test(
  'con la marca del producto, ensena el nombre en texto y no un logotipo',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubKioskApi(page) // el doble por defecto ya es la marca del producto.

    await page.goto('/')

    await expect(page.getByTestId('brand-name')).toHaveText('KronoQR')
    await expect(page.getByTestId('brand-logo')).toHaveCount(0)
    await expect.poll(() => page.title()).toBe('KronoQR')
    await expect(page.locator('html')).toHaveAttribute('data-kq-branded', 'product')
  },
)

test(
  'con un solo idioma ofrecido, el selector no se pinta (doc 06 regla 8, RF-KI-05, RF-PD-08)',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubKioskApi(page)
    // «Hotel Marina» solo ofrece espanol (ver el doble): no hay nada que
    // elegir, asi que el `role="group"` entero deja de pintarse.
    await stubBrandingApi(page, { variant: 'hotel-marina' })

    await page.goto('/')

    await expect(page.getByTestId('brand-logo')).toBeVisible()
    await expect(page.getByRole('group', { name: 'Idioma' })).toHaveCount(0)
    // Y el idioma activo sigue siendo el que ofrece la instalacion.
    await expect(page.locator('html')).toHaveAttribute('lang', 'es')
  },
)

test(
  'el nombre y el color sobreviven sin red, restaurados desde localStorage (RF-KI-03, RF-PD-08)',
  { tag: ['@RF-KI-03', '@RF-PD-08'] },
  async ({ page }) => {
    await stubKioskApi(page)
    await stubBrandingApi(page, { variant: 'hotel-marina' })

    // 1. Primera carga, CON red: la marca llega del servidor y `useBranding`
    //    la guarda en `localStorage` (JSON crudo del contrato).
    await page.goto('/')
    await expect(page.getByTestId('brand-logo')).toBeVisible()
    await expect.poll(() => page.title()).toBe('Hotel Marina')

    // 2. Se condena `GET /api/v1/branding` al fracaso, SIN tocar
    //    `navigator.onLine` (igual que `offline.spec.ts`: un `route()`
    //    registrado despues gana al que ya fulfillaba la respuesta). Esto
    //    intercepta con fiabilidad la peticion que hace NUESTRO codigo
    //    (`shared/api/client.ts`, un `fetch()` de la pagina): es la parte de
    //    esta prueba que Playwright puede afirmar con certeza.
    await page.route('**/api/v1/branding', async (route) => route.abort('failed'))

    // 3. Recarga: `applyCachedBranding()` (`main.ts`) pinta lo que hay en
    //    `localStorage` ANTES de que exista ninguna peticion, y el intento de
    //    refresco que hace `useBranding` en segundo plano falla en silencio
    //    (regla dura 19): el nombre y el color de acento no dependen de la
    //    red para sobrevivir a una recarga.
    await page.reload()

    await expect.poll(() => page.title()).toBe('Hotel Marina')
    await expect(page.locator('html')).toHaveAttribute('data-kq-branded', 'accent')
    // Y el quiosco sigue vivo: la marca nunca bloquea el fichaje (regla dura 19).
    await expect(page.getByTestId('privacy-notice')).toBeVisible()
  },
)

// LO QUE NO SE HA PODIDO AFIRMAR AQUI (documentado a proposito, brief de la
// tarea): que el LOGOTIPO en si -sus bytes, no el nombre- sigue viendose tras
// una recarga con la red muerta, servido por la cache `CacheFirst` del
// *service worker* (`kronoqr-branding-logo` en `vite.config.ts`). Se ha
// intentado con dos enfoques y los dos son o poco fiables o directamente
// falsos en este arnes:
//
//   1. `context.setOffline(true)` + recarga: no aisla que el logotipo venga
//      de la cache o de la red, porque no hay forma de comprobarlo sin
//      inspeccionar la cache del *service worker*.
//   2. `page.route(...).abort()` sobre `/branding/logo` tras la primera
//      carga, luego recargar: el `<img>` de la recarga SIGUE fallando (404/500
//      "de verdad", `Response.fromServiceWorker() === true` pero con el
//      cuerpo que devuelve el `vite preview` real, no el doble de
//      Playwright). La explicacion mas probable, confirmada con
//      `caches.keys()`: `vite-plugin-pwa` en `generateSW` NO activa
//      `clientsClaim`, asi que el *service worker* no controla la PRIMERA
//      carga que lo instala -esa peticion del logotipo va a la red directa,
//      interceptable por Playwright- y en la SEGUNDA carga (ya controlada
//      por el SW) el `fetch()` de reintento de `CacheFirst` lo hace el propio
//      *service worker*, en su contexto, no en el de la pagina: `page.route()`
//      no lo ve, así que no se le puede "cortar la red" desde aqui sin que la
//      afirmacion sea falsa (parece que fallo por mi mock, no por la cache).
//
// Lo que SI queda probado con fiabilidad: (a) el navegador pinta el logotipo
// que sirve el servidor la primera vez (arriba); (b) el nombre y el color -que
// NO dependen del service worker, viven en `localStorage`- sobreviven a una
// recarga sin red (arriba). Verificar el logotipo en si hace falta en un
// dispositivo real o con un arnes que controle `clientsClaim`/el ciclo de vida
// del *service worker* con mas precision que este.
