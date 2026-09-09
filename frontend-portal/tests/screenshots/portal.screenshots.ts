// Generador de capturas del portal del empleado, para
// `docs/cliente/guia-portal-empleado.md` (tarea 5.11b, bloque C).
//
// NO ES UNA PRUEBA DE LA CI: no hay backend real ni assertion de negocio que
// proteger aqui, solo el recorrido minimo -acceso, mi registro con una
// correccion visible, y descarga- parado en los puntos exactos que pide la
// ficha de la tarea. Se ejecuta a mano con `npm run docs:screenshots` y
// escribe fuera de `frontend-portal/`, en `docs/cliente/img/`.
//
// SIN DATOS REALES (regla dura 21): «Youssef Amrani» es la misma
// demostracion que usan los generadores del panel y del quiosco, en los dos
// idiomas.
//
// EL IDIOMA LO DECIDE EL PROYECTO (`playwright.screenshots.config.ts`: `es` o
// `en`), y `stubPortalApi(page, { locale })` hace que la SESION que emite el
// doble sea de ese idioma: sin eso, el portal volveria al español en cuanto
// llega `PortalEmployee.locale` (el `watch` de `main.ts` lo adopta, no el del
// navegador).
import { expect, test } from '@playwright/test'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { PORTAL_EMPLOYEE_CODE, PORTAL_PIN, stubPortalApi } from '../e2e/support/portal'

type Locale = 'es' | 'en'

const HERE = fileURLToPath(new URL('.', import.meta.url))
/** `frontend-portal/tests/screenshots` -> raiz del repositorio. */
const REPO_ROOT = resolve(HERE, '../../..')
const IMG_ROOT = join(REPO_ROOT, 'docs', 'cliente', 'img')

interface Dictionary {
  loginHeading: string
  recordsHeading: string
  correctionHeading: RegExp
  exportHeading: string
  downloadButton: string
  downloadDone: RegExp
}

/**
 * Extraidas literalmente de `src/shared/i18n/locales/{es,en}.json` (claves
 * `login.heading`, `myRecords.title`, `myRecords.history.heading`,
 * `myExport.title`, `myExport.download`): NO se importa el JSON en tiempo de
 * ejecucion (evita depender del alias `@/*` fuera del pipeline de Vite), se
 * copian aqui igual que ya hace el generador del asistente en
 * `frontend-admin`.
 */
const DICTIONARIES: Record<Locale, Dictionary> = {
  es: {
    loginHeading: 'Acceso al portal',
    recordsHeading: 'Mi registro horario',
    correctionHeading: /Historial de correcciones/,
    exportHeading: 'Descargar mi historial',
    downloadButton: 'Descargar CSV',
    downloadDone: /Descarga generada/,
  },
  en: {
    loginHeading: 'Sign in to the portal',
    recordsHeading: 'My working time record',
    correctionHeading: /Correction history/,
    exportHeading: 'Download my history',
    downloadButton: 'Download CSV',
    downloadDone: /Download ready/,
  },
}

function isLocale(value: string): value is Locale {
  return value === 'es' || value === 'en'
}

/**
 * El sello de version (misma convencion que los otros dos generadores):
 * copia literal de `VERSION`, para que una prueba de arquitectura pueda
 * exigir que las capturas se regeneren en cada version menor.
 */
function sealVersion(): void {
  const version = readFileSync(join(REPO_ROOT, 'VERSION'))

  writeFileSync(join(IMG_ROOT, 'VERSION'), version)
}

test('genera las cuatro capturas del portal del empleado', async ({ page }, testInfo) => {
  const projectName = testInfo.project.name

  if (!isLocale(projectName)) {
    throw new Error(`Proyecto sin diccionario de capturas: «${projectName}».`)
  }

  const locale = projectName
  const dict = DICTIONARIES[locale]
  const outDir = join(IMG_ROOT, locale)

  mkdirSync(outDir, { recursive: true })

  async function shoot(name: string, options: { fullPage?: boolean } = {}): Promise<void> {
    await page.screenshot({
      path: join(outDir, `${name}.png`),
      fullPage: options.fullPage ?? false,
      animations: 'disabled',
    })
  }

  await stubPortalApi(page, { locale })

  // 1. Acceso: codigo de empleado y PIN RELLENOS, antes de enviar.
  await page.goto('/login')
  await expect(page.getByRole('heading', { name: dict.loginHeading })).toBeVisible()
  await page.locator('input[name="employee_code"]').fill(PORTAL_EMPLOYEE_CODE)
  await page.locator('input[name="pin"]').fill(PORTAL_PIN)
  await shoot('portal-01-acceso')

  await page.locator('form button[type="submit"]').click()
  await page.waitForURL('**/records')

  // 2. Mi registro: el resumen grande arriba y el detalle de la primera
  // jornada debajo, tal como lo ve quien abre el portal -sin pagina
  // completa: once mil pixeles de jornadas apiladas no caben en una guia.
  await expect(page.getByRole('heading', { level: 1, name: dict.recordsHeading })).toBeVisible()
  const firstWorkday = page.getByTestId('workday').first()
  await expect(firstWorkday).toBeVisible()
  // Se desplaza para que la tabla de tramos entre en el encuadre: los
  // filtros por si solos no dicen nada de «tramos».
  await firstWorkday.scrollIntoViewIfNeeded()
  await shoot('portal-02-jornadas')

  // 3. La correccion visible: motivo y valor anterior, sin dar a entender que
  // se ha borrado nada (regla dura 5). Se desplaza hasta ella para que la
  // captura -a viewport, no pagina completa- la encuadre entera.
  const correction = page.getByTestId('correction').first()
  // Cada jornada tiene su propio encabezado «Historial de correcciones»
  // (tambien las que no se corrigieron): se acota al `<section>` que
  // contiene la correccion real -su ancestro directo-, no al primero de la
  // pagina.
  const correctionSection = correction.locator('xpath=ancestor::section[1]')
  await correction.scrollIntoViewIfNeeded()
  await expect(correction).toBeVisible()
  await expect(
    correctionSection.getByRole('heading', { name: dict.correctionHeading }),
  ).toBeVisible()
  await shoot('portal-03-correccion-visible')

  // 4. Descargar mi historial: el CSV se genera y confirma en pantalla.
  await page.goto('/export')
  await expect(page.getByRole('heading', { level: 1, name: dict.exportHeading })).toBeVisible()

  const download = page.waitForEvent('download')
  await page.getByRole('button', { name: dict.downloadButton }).click()
  await download

  // El anunciador del marco (`role="status"`, `sr-only`) repite el mismo
  // texto: se acota a `#main`, donde vive la confirmacion visible de verdad.
  await expect(page.locator('#main').getByText(dict.downloadDone)).toBeVisible()
  await shoot('portal-04-descarga')

  sealVersion()
})
