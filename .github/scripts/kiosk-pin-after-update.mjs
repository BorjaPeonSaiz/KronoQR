// KronoQR · etapa ⑧b de la CI: el navegador del quiosco, antes y despues de
// `update.sh` (tarea 3.2 del bloque 3 de la 2.2.1; RF-AT-11, RF-KI-07, RF-PD-10).
//
// NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo ejecuta la CI, desde
// `kiosk-pin-after-update.sh`, que prepara los datos (administrador, centro,
// empleados con PIN) y confirma el emparejamiento. Aqui solo hay navegador.
//
// POR QUE EXISTE. La tablet de la demo tenia la PWA de la 2.1.0 en cache. Al
// instalar la 2.2.0 el servidor ya servia una CSP con `wasm-unsafe-eval`, pero
// la tablet seguia con el documento y las cabeceras que su Service Worker
// guardo: sin esa directiva el navegador no compila el WebAssembly de
// libsodium, el sellado del PIN falla y el quiosco decia «Código no válido»
// sin mandar nada al servidor. Ninguna prueba lo veia: `pin-csp.spec.ts` abre
// un navegador NUEVO contra un build y una CSP, nunca un navegador que ya
// estaba abierto cuando cambio el servidor.
//
// ALCANCE EN ⑧b. La version anterior de este escenario es >= 2.2.0, que ya sirve
// `wasm-unsafe-eval`: aqui no se reproduce la CSP de la 2.1.0 (eso lo cubre
// `frontend-kiosk/tests/e2e/kiosk-upgrade.spec.ts`). Lo que se caza es la
// compatibilidad de una PWA YA EN CACHE (token, roster, Service Worker y sus
// cabeceras) con el servidor nuevo despues de `update.sh`.
//
// FASES (el estado entre ellas es un PERFIL PERSISTENTE de Chromium: token en
// localStorage, roster en IndexedDB y Service Worker con su cache, que es lo
// que conserva una tablet entre dos dias):
//
//   before   Instalacion anterior en pie. Abre el quiosco sin token, pide el
//            codigo de emparejamiento (lo deja en `pairing-code` para que el
//            script de shell lo confirme por consola), recoge el token, espera
//            a que el Service Worker controle la pagina y ficha por PIN con el
//            empleado 0 (referencia: si falla aqui, el defecto no es de la
//            actualizacion). Cierra el navegador.
//   after    `update.sh` ya ha terminado. Reabre EL MISMO perfil y comprueba:
//              1. el quiosco sigue emparejado (no vuelve a /pair);
//              2. fichaje por PIN con el empleado 1 desde lo que la tablet
//                 tenia en cache, sin violaciones de CSP ni errores de
//                 WebAssembly;
//              3. recuperacion «plan B» (desregistrar el Service Worker y
//                 vaciar las caches, como manda el runbook): recarga con el
//                 build NUEVO bajo las cabeceras NUEVAS y fichaje por PIN con
//                 el empleado 2.
//
// LO QUE NO PRUEBA. La version anterior de ⑧b es la 2.2.0, que no conoce la
// actualizacion urgente (version minima del servidor): aqui la tablet se pone
// al dia por su ventana o por la recarga manual, no al instante. Esa
// actualizacion la cubre `frontend-kiosk/tests/e2e/kiosk-upgrade.spec.ts`. Y
// el navegador NO esta abierto durante `update.sh`: se cierra y se reabre con
// su perfil, que es lo que importa (cache y token), no el proceso.
//
// El quiosco vive en /kiosk/. La hora local del navegador se fija a las 11:00 con la zona
// horaria (no con el reloj), fuera de la ventana de actualizacion 03:00-05:00.
// Los PIN viajan por un fichero 0600 y NUNCA se imprimen.
//
// Entorno:
//   KQ_KIOSK_STATE_DIR   (obligatorio) directorio con `state.json` y `profile/`.
//   KQ_KIOSK_BASE_URL    (https://kronoqr.ci.local) donde responde el borde.
//
// Salida: 0 todo comprobado · 1 algo no se cumple · 2 uso incorrecto.

import { chromium } from '@playwright/test'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

const PHASE = process.argv[2]
const STATE_DIR = process.env.KQ_KIOSK_STATE_DIR
const BASE_URL = process.env.KQ_KIOSK_BASE_URL ?? 'https://kronoqr.ci.local'
// El quiosco vive en /kiosk/ (infra/docker/nginx/extra/spa.conf), no en la raiz: con
// `baseURL` + `goto('/')` se perdia el prefijo y se pedia la raiz del borde.
const KIOSK_URL = new URL('/kiosk/', BASE_URL).toString()
const PAIR_PATH = '/kiosk/pair'

// La ventana de actualizacion de la tablet (03:00-05:00 por defecto,
// `updateWindow.ts`) se evalua con la HORA LOCAL del navegador. Si la CI cayera
// en ella, la PWA podria recargarse sola a mitad del fichaje. En vez de falsear
// el reloj (`page.clock` desfasaria `occurred_at` frente al servidor y daria
// incidencias de desfase), se elige la ZONA HORARIA del navegador que deja la
// hora local en las 11:00 con el reloj real: sin timers falsos ni desfase.
function timezoneAtEleven() {
  const utcHour = new Date().getUTCHours()
  let offset = 11 - utcHour
  if (offset > 12) offset -= 24
  if (offset < -12) offset += 24
  if (offset === 0) return 'UTC'
  return `Etc/GMT${offset > 0 ? '-' : '+'}${Math.abs(offset)}`
}


if (!['before', 'after'].includes(PHASE ?? '') || !STATE_DIR) {
  console.error('uso: KQ_KIOSK_STATE_DIR=DIR kiosk-pin-after-update.mjs before|after')
  process.exit(2)
}

const PROFILE_DIR = join(STATE_DIR, 'profile')
const DIAGNOSTICS_DIR = join(STATE_DIR, 'diagnostico')
mkdirSync(DIAGNOSTICS_DIR, { recursive: true })

/** @type {{ employees: { code: string, pin: string }[] }} */
const state = JSON.parse(readFileSync(join(STATE_DIR, 'state.json'), 'utf8'))

// Lo que deja en consola o en `pageerror` un WebAssembly que la CSP no deja
// compilar, o cualquier otra violacion de CSP (mismo patron que pin-csp.spec.ts).
const BLOCKED = /CompileError|WebAssembly|wasm|Content Security Policy|Refused to/i

const diagnostics = []
let step = 'arranque'

function log(message) {
  console.log(`  ok · ${message}`)
}

function begin(name) {
  step = name
  console.log(`\n=== ${name}`)
}

function fail(message) {
  throw new Error(message)
}

function watch(page) {
  page.on('pageerror', (error) => diagnostics.push(`pageerror: ${error.name}: ${error.message}`))
  page.on('console', (message) => diagnostics.push(`console.${message.type()}: ${message.text()}`))
}

async function cspViolationsToConsole(page) {
  await page.addInitScript(() => {
    document.addEventListener('securitypolicyviolation', (event) => {
      console.error(`Content Security Policy violation: ${event.violatedDirective}`)
    })
  })
}

function blocked() {
  return diagnostics.filter((text) => BLOCKED.test(text))
}

async function launch() {
  return chromium.launchPersistentContext(PROFILE_DIR, {
    headless: true,
    // El certificado de la CI es autofirmado. Los dos: el segundo es el que
    // hace falta para que el Service Worker se registre sobre ese certificado.
    ignoreHTTPSErrors: true,
    args: [
      '--ignore-certificate-errors',
      '--use-fake-device-for-media-stream',
      '--use-fake-ui-for-media-stream',
      '--autoplay-policy=no-user-gesture-required',
    ],
    permissions: ['camera'],
    viewport: { width: 1280, height: 800 },
    locale: 'es-ES',
    timezoneId: timezoneAtEleven(),
  })
}

/** Teclea codigo y PIN en la pantalla de respaldo y devuelve el desenlace. */
async function clockInWithPin(page, employee, label) {
  await page.getByTestId('pin-entry-link').click({ timeout: 30_000 })
  await page.getByTestId('pin-code-input').fill(employee.code)
  await page.getByTestId('pin-code-continue').click()
  for (const digit of employee.pin) {
    await page.getByRole('button', { name: digit, exact: true }).click()
  }
  await page.getByTestId('pin-confirm').click()
  const confirmation = page.getByTestId('scan-confirmation')
  await confirmation.waitFor({ state: 'visible', timeout: 20_000 })
  const kind = await confirmation.getAttribute('data-kind')
  if (kind !== 'accepted') {
    const headline = (await page.getByTestId('confirmation-headline').textContent()) ?? ''
    fail(
      `${label}: el fichaje por PIN termino en «${kind}» («${headline.trim()}») y se esperaba «accepted». ` +
        'Si el titular dice «Código no válido» sin que el servidor reciba nada, es el sellado del PIN ' +
        '(WebAssembly bloqueado por la CSP de lo que la tablet tenia en cache).',
    )
  }
  const hits = blocked()
  if (hits.length > 0) {
    fail(`${label}: el fichaje se acepto pero el navegador registro bloqueos de CSP o WebAssembly:\n${hits.join('\n')}`)
  }
  log(`${label}: fichaje por PIN aceptado, sin violaciones de CSP`)
}

async function waitForPinEntry(page, label) {
  try {
    await page.getByTestId('pin-entry-link').waitFor({ state: 'visible', timeout: 45_000 })
  } catch {
    const url = page.url()
    fail(
      `${label}: no aparece «Fichar con PIN» (URL ${url}). Si es /pair, el token del quiosco ya no vale; ` +
        'si es la pantalla de fichaje sin el enlace, el roster no trae la clave de sellado del PIN.',
    )
  }
}

async function controlledByServiceWorker(page) {
  await page.evaluate(() => navigator.serviceWorker.ready.then(() => true))
  // Un Service Worker recien activado no controla la pagina que lo registro
  // hasta la siguiente carga: una recarga lo deja controlando de verdad.
  await page.reload({ waitUntil: 'load' })
  return page.evaluate(() => navigator.serviceWorker.controller !== null)
}

async function runBefore(context) {
  const page = context.pages()[0] ?? (await context.newPage())
  watch(page)
  await cspViolationsToConsole(page)

  begin('before · 1 · pedir el codigo de emparejamiento')
  await page.goto(KIOSK_URL)
  await page.getByTestId('pairing-code').waitFor({ state: 'visible', timeout: 30_000 })
  const code = ((await page.getByTestId('pairing-code').textContent()) ?? '').replace(/\D/g, '')
  if (!/^\d{6}$/.test(code)) fail(`el codigo de emparejamiento no son seis digitos: «${code}»`)
  // El script de shell espera este fichero, lo confirma por consola y deja que
  // este proceso siga: el sondeo de la tablet recoge el token solo.
  writeFileSync(join(STATE_DIR, 'pairing-code'), `${code}\n`, { mode: 0o600 })
  log('codigo pedido y entregado al script de shell')

  begin('before · 2 · recoger el token y llegar a la pantalla de fichaje')
  await page.waitForURL((url) => url.pathname !== PAIR_PATH, { timeout: 90_000 })
  await waitForPinEntry(page, 'before')
  const token = await page.evaluate(() => localStorage.getItem('kronoqr.kiosk.device_token'))
  if (!token) fail('el quiosco no guardo el token del emparejamiento')
  log('emparejado, con el token guardado y el roster descargado')

  begin('before · 3 · el Service Worker controla la pagina (la PWA queda en cache)')
  if (!(await controlledByServiceWorker(page))) {
    fail(
      'el Service Worker no controla la pagina tras recargar: sin el no hay PWA en cache y la prueba no ' +
        'reproduce a la tablet. Si el registro falla, comprueba que Chromium acepta el certificado autofirmado.',
    )
  }
  await waitForPinEntry(page, 'before')
  log('pagina controlada por el Service Worker')

  begin('before · 4 · referencia: fichaje por PIN con la version anterior')
  await clockInWithPin(page, state.employees[0], 'before')
}

async function runAfter(context) {
  const page = context.pages()[0] ?? (await context.newPage())
  watch(page)
  await cspViolationsToConsole(page)

  begin('after · 1 · reabrir el quiosco tras actualizar: sigue emparejado')
  const response = await page.goto(KIOSK_URL)
  const csp = response?.headers()['content-security-policy'] ?? '(sin cabecera)'
  if (!response?.fromServiceWorker()) {
    fail(
      'after: el documento no salio del Service Worker, asi que la tablet no estaba sirviendo su PWA de cache y la prueba ' +
        'no reproduce lo que dice. Si el Service Worker no controla la pagina, mira la fase before (paso 3).',
    )
  }
  console.log('  documento servido por el Service Worker (cache de la version anterior)')
  console.log(`  script-src del documento: ${(csp.match(/script-src[^;]*/) ?? ['(ninguna)'])[0]}`)
  // El guard del router decide en el cliente: se espera a que muestre una de las dos pantallas.
  await page.getByTestId('pin-entry-link').or(page.getByTestId('pairing-code')).first().waitFor({ state: 'visible', timeout: 45_000 })
  if (new URL(page.url()).pathname === PAIR_PATH) {
    fail('after: el quiosco volvio a la pantalla de emparejamiento tras actualizar (el token ya no vale)')
  }
  await waitForPinEntry(page, 'after')
  log('sigue emparejado y ofrece el PIN')

  begin('after · 2 · fichaje por PIN desde lo que la tablet tenia en cache')
  await clockInWithPin(page, state.employees[1], 'after (cache)')

  begin('after · 3 · plan B: desregistrar el Service Worker, vaciar caches y recargar')
  await page.evaluate(async () => {
    for (const registration of await navigator.serviceWorker.getRegistrations()) {
      await registration.unregister()
    }
    for (const key of await caches.keys()) await caches.delete(key)
  })
  diagnostics.length = 0
  const fresh = await page.goto(KIOSK_URL)
  if (fresh?.fromServiceWorker()) fail('after: tras desregistrar, el documento sigue saliendo del Service Worker')
  await waitForPinEntry(page, 'after (build nuevo)')
  const cspAfter = fresh?.headers()['content-security-policy'] ?? ''
  if (!/wasm-unsafe-eval/.test(cspAfter)) {
    fail('after: la CSP que sirve el borde NUEVO no lleva wasm-unsafe-eval; el PIN no puede sellarse')
  }
  await clockInWithPin(page, state.employees[2], 'after (build nuevo)')
}

let context
try {
  context = await launch()
  if (PHASE === 'before') await runBefore(context)
  else await runAfter(context)
  console.log(`\nFase ${PHASE}: todo comprobado.`)
} catch (error) {
  console.error(`\nFALLO en «${step}»: ${error instanceof Error ? error.message : String(error)}`)
  const page = context?.pages()[0]
  if (page) {
    await page.screenshot({ path: join(DIAGNOSTICS_DIR, `${PHASE}.png`) }).catch(() => undefined)
  }
  writeFileSync(join(DIAGNOSTICS_DIR, `${PHASE}-consola.log`), `${diagnostics.join('\n')}\n`)
  process.exitCode = 1
} finally {
  await context?.close()
}
