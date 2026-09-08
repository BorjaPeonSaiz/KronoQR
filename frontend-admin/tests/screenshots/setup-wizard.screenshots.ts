// Generador de capturas del asistente de puesta en marcha (RF-PD-03,
// RF-PD-06, RF-GP-05), para `docs/cliente/instalacion.md` §1.7 (tarea 5.11,
// bloque 5.11-D, decision 3 de la ficha).
//
// NO ES UNA PRUEBA DE LA CI: no hay backend real ni assertion de negocio que
// proteger aqui, solo el recorrido que ya cubre `tests/e2e/setup-wizard.spec.ts`
// —con el MISMO doble, `stubOnboardingApi`— parado en los puntos exactos que
// pide la tabla de la ficha. Se ejecuta a mano con `npm run docs:screenshots`
// y escribe fuera de `frontend-admin/`, en `docs/cliente/img/`.
//
// SIN DATOS REALES (regla dura 21): «Hotel Marina», «Youssef Amrani» y
// «Recepción» son la misma demostracion del E2E, en los dos idiomas — no se
// traducen, igual que un nombre propio no se traduce en la vida real.
//
// EL IDIOMA LO DECIDE EL PROYECTO (`playwright.screenshots.config.ts`: `es` o
// `en`), y `stubOnboardingApi(page, { locale })` hace que la CUENTA que emite
// el doble sea de ese idioma: sin eso, el panel volveria al español en cuanto
// se confirma el segundo factor del paso 1 (el `watch` de `main.ts` adopta el
// idioma de `session.user.locale`, no el del navegador).
import { expect, test } from '@playwright/test'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { PAIRING_CODE, stubOnboardingApi, TOTP_CODE } from '../e2e/support/setupWizard'

type Locale = 'es' | 'en'

const HERE = dirname(fileURLToPath(import.meta.url))
/** `frontend-admin/tests/screenshots` -> raiz del repositorio. */
const REPO_ROOT = resolve(HERE, '../../..')
const IMG_ROOT = join(REPO_ROOT, 'docs', 'cliente', 'img')

/** Nombre de demostracion compartido por el departamento y el quiosco (§10.2). */
const RECEPCION = 'Recepción'

interface Dictionary {
  administratorName: string
  administratorEmail: string
  administratorPassword: string
  labelName: string
  labelEmail: string
  labelPassword: string
  submitAdministrator: string
  labelEnrolCode: RegExp
  submitEnrol: string
  qrAlt: string
  continue: string
  labelEstablishmentName: string
  labelSiteName: string
  labelDepartmentName: string
  addDepartment: string
  licenseHeading: string
  labelKioskCode: string
  labelKioskName: string
  submitKiosk: string
  kioskConfirmed: string
  reviewHeading: string
  completionHeading: string
}

/**
 * Extraidas literalmente de `src/shared/i18n/locales/{es,en}.json` (claves
 * `onboarding.*`, `auth.twoFactor.*`, `devices.pair.*`): NO se importa el JSON
 * en tiempo de ejecucion (evita depender del alias `@/*` fuera del pipeline de
 * Vite) sino que se copian aqui, igual que ya hace `setup-wizard.spec.ts` con
 * los textos en español.
 */
const DICTIONARIES: Record<Locale, Dictionary> = {
  es: {
    administratorName: 'Dirección del hotel',
    administratorEmail: 'direccion@hotel.example',
    administratorPassword: 'una-contrasena-larga-y-propia-1!',
    labelName: 'Nombre',
    labelEmail: 'Correo electrónico',
    labelPassword: 'Contraseña',
    submitAdministrator: 'Crear la cuenta',
    labelEnrolCode: /Código del autenticador/,
    submitEnrol: 'Activar y entrar',
    qrAlt: 'Código QR para configurar el segundo factor en tu aplicación de autenticación',
    continue: 'Continuar',
    labelEstablishmentName: 'Nombre del establecimiento',
    labelSiteName: 'Nombre del centro',
    labelDepartmentName: 'Nombre del departamento',
    addDepartment: 'Añadir',
    licenseHeading: 'Licencia',
    labelKioskCode: 'Código de emparejamiento',
    labelKioskName: 'Nombre del quiosco',
    submitKiosk: 'Vincular',
    kioskConfirmed: `Se ha vinculado el quiosco «${RECEPCION}».`,
    reviewHeading: 'Revisa antes de terminar',
    completionHeading: 'Puesta en marcha completada',
  },
  en: {
    administratorName: 'Hotel management',
    administratorEmail: 'direccion@hotel.example',
    administratorPassword: 'una-contrasena-larga-y-propia-1!',
    labelName: 'Name',
    labelEmail: 'Email',
    labelPassword: 'Password',
    submitAdministrator: 'Create the account',
    labelEnrolCode: /Authenticator code/,
    submitEnrol: 'Activate and sign in',
    qrAlt: 'QR code to set up the second factor in your authenticator app',
    continue: 'Continue',
    labelEstablishmentName: 'Establishment name',
    labelSiteName: 'Site name',
    labelDepartmentName: 'Department name',
    addDepartment: 'Add',
    licenseHeading: 'Licence',
    labelKioskCode: 'Pairing code',
    labelKioskName: 'Kiosk name',
    submitKiosk: 'Link',
    kioskConfirmed: `Kiosk "${RECEPCION}" has been linked.`,
    reviewHeading: 'Review before finishing',
    completionHeading: 'Setup completed',
  },
}

function isLocale(value: string): value is Locale {
  return value === 'es' || value === 'en'
}

/**
 * El sello de version (decision 3 de la ficha 5.11): copia literal de
 * `VERSION`, para que una prueba de arquitectura pueda exigir que las
 * capturas se regeneren en cada version menor. Idempotente: los dos proyectos
 * (`es`, `en`) lo escriben con el mismo contenido.
 */
function sealVersion(): void {
  const version = readFileSync(join(REPO_ROOT, 'VERSION'))

  writeFileSync(join(IMG_ROOT, 'VERSION'), version)
}

test('genera las doce capturas del asistente de puesta en marcha', async ({ page }, testInfo) => {
  const projectName = testInfo.project.name

  if (!isLocale(projectName)) {
    throw new Error(`Proyecto sin diccionario de capturas: «${projectName}».`)
  }

  const locale = projectName
  const dict = DICTIONARIES[locale]
  const outDir = join(IMG_ROOT, locale)

  mkdirSync(outDir, { recursive: true })

  /**
   * Los dos pasos cuyo contenido no cabe en 1366x768 sin recortar algo que la
   * ficha pide visible: el boton «activar y entrar» del segundo factor, y los
   * umbrales del perfil de convenio («con los umbrales a la vista», tabla de
   * la ficha). El resto de pasos cabe entero en el visor real.
   */
  const FULL_PAGE = new Set(['asistente-01-segundo-factor', 'asistente-05-convenio'])

  async function shoot(name: string): Promise<void> {
    await page.screenshot({
      path: join(outDir, `${name}.png`),
      fullPage: FULL_PAGE.has(name),
      animations: 'disabled',
    })
  }

  await stubOnboardingApi(page, { locale })
  await page.goto('/setup')

  // Paso 1: primer administrador, formulario RELLENO, antes de enviar.
  await page.getByLabel(dict.labelName).fill(dict.administratorName)
  await page.getByLabel(dict.labelEmail).fill(dict.administratorEmail)
  await page.getByLabel(dict.labelPassword).fill(dict.administratorPassword)
  await shoot('asistente-01-administrador')
  await page.getByRole('button', { name: dict.submitAdministrator }).click()

  // Segundo factor: QR, secreto en texto y el campo del codigo, sin rellenar.
  await expect(page.getByRole('img', { name: dict.qrAlt })).toBeVisible()
  await expect(page.getByTestId('two-factor-secret')).toBeVisible()
  await expect(page.getByLabel(dict.labelEnrolCode)).toBeVisible()
  await shoot('asistente-01-segundo-factor')
  await page.getByLabel(dict.labelEnrolCode).fill(TOTP_CODE)
  await page.getByRole('button', { name: dict.submitEnrol }).click()

  // Paso 2: organizacion, con «Hotel Marina» escrito.
  await page.getByLabel(dict.labelEstablishmentName).fill('Hotel Marina')
  await shoot('asistente-02-organizacion')
  await page.getByRole('button', { name: dict.continue }).click()

  // Paso 3: centro de trabajo (la zona horaria ya trae Europe/Madrid de serie).
  await page.getByLabel(dict.labelSiteName).fill('Hotel Marina')
  await shoot('asistente-03-centro')
  await page.getByRole('button', { name: dict.continue }).click()

  // Paso 4: departamentos, con «Recepción» ya añadido.
  await page.getByLabel(dict.labelDepartmentName).fill(RECEPCION)
  await page.getByRole('button', { name: dict.addDepartment }).click()
  await expect(page.getByTestId('department-list')).toContainText(RECEPCION)
  await shoot('asistente-04-departamentos')
  await page.getByTestId('continue').click()

  // Paso 5: perfil de convenio, ES-hosteleria con los umbrales a la vista.
  await expect(page.getByTestId('confirm-compliance-profile')).toBeVisible()
  await shoot('asistente-05-convenio')
  await page.getByTestId('confirm-compliance-profile').click()

  // Paso 6: plantilla, tras VALIDAR el CSV: el informe con «Youssef Amrani» y
  // el boton de aplicar, antes de pulsarlo.
  await page.getByTestId('import-file').setInputFiles({
    name: 'plantilla.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from('first_name,last_name\nYoussef,Amrani\n'),
  })
  await page.getByTestId('validate').click()
  await expect(page.getByTestId('import-row-2')).toContainText('Youssef Amrani')
  await shoot('asistente-06-plantilla')
  await page.getByTestId('apply').click()
  await expect(page.getByTestId('apply')).toHaveCount(0)
  await page.getByTestId('continue').click()

  // Paso 7: licencia ausente, con el aviso de que el fichaje no depende de ella.
  await expect(page.getByRole('heading', { name: dict.licenseHeading, level: 2 })).toBeVisible()
  await shoot('asistente-07-licencia')
  await page.getByTestId('skip').click()

  // Paso 8: primer quiosco, codigo y nombre escritos, antes de vincular.
  await page.getByLabel(dict.labelKioskCode).fill(PAIRING_CODE)
  await page.getByLabel(dict.labelKioskName).fill(RECEPCION)
  await shoot('asistente-08-quiosco')
  await page.getByRole('button', { name: dict.submitKiosk }).click()
  await expect(page.getByText(dict.kioskConfirmed)).toBeVisible()
  await shoot('asistente-08-quiosco-vinculado')
  await page.getByTestId('continue').click()

  // Revision final: los ocho pasos con su estado.
  await expect(page.getByRole('heading', { name: dict.reviewHeading })).toBeVisible()
  await expect(page.getByTestId('review-list').locator('li')).toHaveCount(8)
  await shoot('asistente-09-revision')
  await page.getByTestId('complete-setup').click()

  // Puesta en marcha completada, con la cifra de tarjetas pendientes.
  await expect(page.getByRole('heading', { name: dict.completionHeading })).toBeVisible()
  await expect(page.getByTestId('credentials-alert')).toBeVisible()
  await shoot('asistente-10-completado')

  sealVersion()
})
