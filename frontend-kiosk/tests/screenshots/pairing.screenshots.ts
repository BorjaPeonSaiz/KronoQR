// Capturas de la vinculacion del primer quiosco, para `docs/cliente/instalacion.md`
// §1.7 y el runbook `alta-nuevo-quiosco.md` (tarea 5.11-E, decision 3 del
// cierre de la 5.11).
//
// Reutiliza el doble del emparejamiento de `pairing.spec.ts`
// (`tests/e2e/support/pairing.ts`) y la camara simulada de siempre, con un
// fotograma SIN QR (`qr-video-blank.y4m`, ver `playwright.screenshots.config.ts`)
// para que la segunda captura sea la pantalla de fichaje YA operativa pero
// SIN NADIE FICHANDO, tal como pide el contrato.
//
// NOMBRES DE FICHERO FIJOS. No los cambies sin acordarlo con quien escribe
// `instalacion.md` y el runbook, que enlazan por nombre exacto:
//   quiosco-emparejamiento-codigo.png
//   quiosco-emparejado.png
//
// Se ejecuta a mano con `npm run docs:screenshots` (nunca en CI, decision 3:
// no se suben binarios desde un runner) sobre un `dist/` ya construido.

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'
import { stubBrandingApi } from '../e2e/support/kiosk'
import { PAIRING_CODE, stubPairing } from '../e2e/support/pairing'

const IMG_ROOT = fileURLToPath(new URL('../../../docs/cliente/img', import.meta.url))
const REPO_ROOT = fileURLToPath(new URL('../../../', import.meta.url))

/**
 * Sondeos de sobra antes de que el doble «confirme» el codigo: unos segundos
 * de margen para cargar la pagina, esperar tipografias y fotografiar el
 * codigo con calma, sin que la vinculacion se resuelva sola a mitad de
 * captura.
 */
const PENDING_POLLS = 6

/** «483 921»: mismo formato que pinta `PairingView.vue`. */
const FORMATTED_CODE = `${PAIRING_CODE.slice(0, 3)} ${PAIRING_CODE.slice(3)}`

test.beforeAll(() => {
  // Mismo sello para las dos aplicaciones (panel y quiosco): el agente del
  // panel escribe el mismo fichero con el mismo contenido, no pasa nada.
  const version = readFileSync(path.join(REPO_ROOT, 'VERSION'))
  mkdirSync(IMG_ROOT, { recursive: true })
  writeFileSync(path.join(IMG_ROOT, 'VERSION'), version)
})

test('vinculacion del primer quiosco: codigo visible y pantalla de fichaje operativa', async ({
  page,
}, testInfo) => {
  const outDir = path.join(IMG_ROOT, testInfo.project.name)
  mkdirSync(outDir, { recursive: true })

  await stubPairing(page, { pendingPolls: PENDING_POLLS, pollIntervalSeconds: 1 })
  // Marca por defecto del producto (RF-PD-08): sin cliente ni datos reales
  // (regla dura 21), que es justo lo que debe verse en una captura generica.
  await stubBrandingApi(page)

  await page.goto('/')

  const code = page.getByTestId('pairing-code')
  await expect(code).toHaveText(FORMATTED_CODE)

  await page.screenshot({
    path: path.join(outDir, 'quiosco-emparejamiento-codigo.png'),
    animations: 'disabled',
  })

  // El sondeo confirma la vinculacion sola, sin que nadie toque nada (regla
  // dura 19): la PWA navega a la pantalla de fichaje.
  await expect(page).toHaveURL(/\/$/, { timeout: 20_000 })

  // Camara activa, sin nadie fichando: el fotograma es blanco (sin QR), asi
  // que el bucle de decodificacion nunca confirma un escaneo y la pantalla se
  // queda en su estado de espera.
  await expect(page.getByTestId('scan-idle')).toBeVisible()
  await expect
    .poll(() => page.evaluate(() => document.querySelector('video')?.readyState ?? 0), {
      timeout: 15_000,
    })
    .toBeGreaterThanOrEqual(2) // HAVE_CURRENT_DATA: hay un fotograma pintado, la camara esta activa de verdad.

  await page.screenshot({
    path: path.join(outDir, 'quiosco-emparejado.png'),
    animations: 'disabled',
  })
})
