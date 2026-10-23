// Contrato del `sw.js` GENERADO (V6-KI-04; RF-KI-01, RF-KI-07).
//
// `kiosk-upgrade.spec.ts` prueba el ciclo real de actualizacion con dos builds;
// esto lee el service worker que sale del build, que es lo que se instala en la
// tablet, y fija tres cosas que una linea de `vite.config.ts` puede romper sin
// que ninguna pantalla cambie:
//
//   1. Sin `clientsClaim` y con `skipWaiting` SOLO bajo el mensaje
//      `SKIP_WAITING`: la version nueva espera a la puerta (RF-KI-07).
//   2. Cada trozo que el quiosco carga con `import()` esta en el precache: el
//      PIN, el emparejamiento, el diagnostico, el decodificador y libsodium. Es
//      lo que hace que un trozo no falle sin red ni tras una actualizacion (17.10).
//   3. La API no tiene respaldo de navegacion: un fichaje servido desde la cache
//      seria un registro inventado.

import { expect, test } from '@playwright/test'

/** Prefijos de los trozos diferidos (`import()` de `router/index.ts`, `useQrScanner.ts`, `pinSealing.ts`). */
const SW_CONTRACT_DEFERRED_CHUNKS = [
  'PinView',
  'PairingView',
  'DiagnosticsView',
  'esm',
  'pinSealing',
] as const

async function generatedServiceWorker(baseURL: string | undefined): Promise<string> {
  const response = await fetch(new URL('sw.js', `${baseURL ?? ''}/`))
  expect(response.status).toBe(200)
  return response.text()
}

function precachedUrls(serviceWorker: string): string[] {
  return [...serviceWorker.matchAll(/url:"([^"]+)"/g)].map((match) => match[1] ?? '')
}

test(
  'el service worker no reclama la pagina ni se activa solo',
  { tag: ['@RF-KI-07'] },
  async ({ baseURL }) => {
    const serviceWorker = await generatedServiceWorker(baseURL)

    expect(serviceWorker).not.toContain('clientsClaim')
    expect(serviceWorker.match(/skipWaiting\(/g)).toHaveLength(1)
    expect(serviceWorker).toMatch(/"SKIP_WAITING"===\w+\.data\.type&&self\.skipWaiting\(\)/)
  },
)

for (const chunk of SW_CONTRACT_DEFERRED_CHUNKS) {
  test(
    `el trozo diferido ${chunk} esta en el precache`,
    { tag: ['@RF-KI-01'] },
    async ({ baseURL }) => {
      const urls = precachedUrls(await generatedServiceWorker(baseURL))

      expect(urls.filter((url) => url.startsWith(`assets/${chunk}-`)).length).toBeGreaterThan(0)
    },
  )
}

test(
  'la navegacion de respaldo excluye la API',
  { tag: ['@RF-KI-01', '@RF-AT-10'] },
  async ({ baseURL }) => {
    const serviceWorker = await generatedServiceWorker(baseURL)

    expect(serviceWorker).toContain('denylist:[/^\\/api\\//]')
  },
)
