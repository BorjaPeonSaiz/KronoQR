// Generador de capturas de la guia de RRHH (tarea 5.11b, bloque B, decision 4
// de la ficha). Produce las imagenes de `docs/cliente/img/<idioma>/` para
// `docs/cliente/guia-rrhh.md` (escrita por otra agente), sobre los MISMOS
// dobles que el E2E del panel (`stubManagementApi`), sin backend y sin datos
// reales (regla dura 21): «Hotel Marina», «Youssef Amrani» y «Recepción» son la
// demostracion que ya usa el resto del panel.
//
// NUNCA EN LA CI, igual que `setup-wizard.screenshots.ts`: comando manual,
// `npx playwright test --config playwright.screenshots.config.ts
// tests/screenshots/hr-guide.screenshots.ts`. Mismo `IMG_ROOT`, mismo sello
// `VERSION`, mismos proyectos `es`/`en` de `playwright.screenshots.config.ts`
// (1366x768). `testDir`/`testMatch` de esa configuracion ya excluyen este
// fichero del E2E habitual y lo incluyen aqui.
//
// `rrhh-08-correccion.png` (bloque B2 de la 5.11b) SI existe desde que
// `CorrectionDialog.vue` cierra el hueco de RF-PA-04 en el panel: el
// dialogo unico de anadir/corregir/anular un tramo, con el motivo del
// catalogo y «que va a cambiar, desde que valor y hacia cual» antes de
// confirmar (regla dura 5). La captura es el modo «Corregir las horas»
// -`PATCH /shift-entries/{uuid}`, ambito `attendance:correct`- porque es el
// que la guia describe con mas detalle (§5.1); el historico de correcciones
// (`rrhh-09`) sigue siendo de solo lectura y muestra el valor anterior
// conservado (RN-13).
import { expect, test, type Page, type TestInfo } from '@playwright/test'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import type { CredentialStatusBoard } from '@/shared/api/types'
import {
  ADMIN_USER,
  COMPLIANCE_PROFILE,
  EMPLOYEE_UUID,
  stubManagementApi,
  USER,
} from '../e2e/support/admin'

type Locale = 'es' | 'en'

const HERE = dirname(fileURLToPath(import.meta.url))
/** `frontend-admin/tests/screenshots` -> raiz del repositorio. */
const REPO_ROOT = resolve(HERE, '../../..')
const IMG_ROOT = join(REPO_ROOT, 'docs', 'cliente', 'img')

/** Nombres de demostracion ya usados en el resto del panel (regla dura 21). */
const RECEPCION = 'Recepción'
const PISOS = 'Pisos'
const YOUSSEF = 'Youssef Amrani'
const LUCIA = 'Lucía Martínez Prieto'

interface Dictionary {
  emailLabel: string
  passwordLabel: string
  signInButton: string
  createAction: string
  createHeading: string
  departmentLabel: string
  firstNameLabel: string
  lastNameLabel: string
  hiredAtLabel: string
  createSubmit: string
  pinRevealHeading: RegExp
  credentialsHeading: string
  instructionsSheetHeading: string
  liveHeading: string
  workdaysHeading: string
  historyHeading: string
  incidentsHeading: string
  resolveButton: string
  resolveDialogHeading: string
  periodReportHeading: string
  generateReport: string
  fromLabel: string
  toLabel: string
  legalExportHeading: string
  legalExportSubmit: string
  legalExportDone: RegExp
  complianceHeading: string
  credentialHeadingInDetail: string
  correctButton: string
  reasonLabel: string
  reasonOtherLabel: string
}

/**
 * Extraidas literalmente de `src/shared/i18n/locales/{es,en}.json`: NO se
 * importa el JSON en tiempo de ejecucion (mismo motivo que
 * `setup-wizard.screenshots.ts`: evita depender del alias `@/*` fuera del
 * pipeline de Vite).
 */
const DICTIONARIES: Record<Locale, Dictionary> = {
  es: {
    emailLabel: 'Correo electrónico',
    passwordLabel: 'Contraseña',
    signInButton: 'Entrar',
    createAction: 'Dar de alta',
    createHeading: 'Alta de empleado',
    departmentLabel: 'Departamento',
    firstNameLabel: 'Nombre',
    lastNameLabel: 'Apellidos',
    hiredAtLabel: 'Fecha de alta',
    createSubmit: 'Dar de alta',
    pinRevealHeading: /PIN emitido/,
    credentialsHeading: 'Credenciales',
    instructionsSheetHeading: 'Hoja de instrucciones',
    liveHeading: 'Presencia en vivo',
    workdaysHeading: 'Registro horario',
    historyHeading: 'Historial de correcciones',
    incidentsHeading: 'Bandeja de incidencias',
    resolveButton: 'Resolver',
    resolveDialogHeading: 'Cerrar incidencia',
    periodReportHeading: 'Informe de horas por periodo',
    generateReport: 'Generar informe',
    fromLabel: 'Desde',
    toLabel: 'Hasta',
    legalExportHeading: 'Exportación para la Inspección de Trabajo',
    legalExportSubmit: 'Generar y descargar',
    legalExportDone: /Exportación descargada/,
    complianceHeading: 'Perfil de cumplimiento',
    credentialHeadingInDetail: 'Tarjeta QR',
    correctButton: 'Corregir las horas',
    reasonLabel: 'Motivo de la corrección',
    reasonOtherLabel: 'Explica el motivo',
  },
  en: {
    emailLabel: 'Email address',
    passwordLabel: 'Password',
    signInButton: 'Sign in',
    createAction: 'Add employee',
    createHeading: 'Add an employee',
    departmentLabel: 'Department',
    firstNameLabel: 'First name',
    lastNameLabel: 'Last name',
    hiredAtLabel: 'Hire date',
    createSubmit: 'Add employee',
    pinRevealHeading: /PIN issued/,
    credentialsHeading: 'Credentials',
    instructionsSheetHeading: 'Instructions sheet',
    liveHeading: 'Live presence',
    workdaysHeading: 'Time record',
    historyHeading: 'Amendment history',
    incidentsHeading: 'Incident inbox',
    resolveButton: 'Resolve',
    resolveDialogHeading: 'Close incident',
    periodReportHeading: 'Hours by period',
    generateReport: 'Generate report',
    fromLabel: 'From',
    toLabel: 'To',
    legalExportHeading: 'Export for the Labour Inspectorate',
    legalExportSubmit: 'Generate and download',
    legalExportDone: /Export downloaded/,
    complianceHeading: 'Compliance profile',
    credentialHeadingInDetail: 'QR card',
    correctButton: 'Correct the times',
    reasonLabel: 'Reason for the correction',
    reasonOtherLabel: 'Explain the reason',
  },
}

function isLocale(value: string): value is Locale {
  return value === 'es' || value === 'en'
}

function localeOf(testInfo: TestInfo): Locale {
  const projectName = testInfo.project.name

  if (!isLocale(projectName)) {
    throw new Error(`Proyecto sin diccionario de capturas: «${projectName}».`)
  }

  return projectName
}

async function shoot(
  page: Page,
  testInfo: TestInfo,
  name: string,
  options: { fullPage?: boolean } = {},
): Promise<void> {
  const outDir = join(IMG_ROOT, localeOf(testInfo))

  mkdirSync(outDir, { recursive: true })
  await page.screenshot({
    path: join(outDir, `${name}.png`),
    fullPage: options.fullPage ?? true,
    animations: 'disabled',
  })
}

/**
 * Entra al panel con la contraseña de demostracion.
 *
 * NO reutiliza `logIn`/`logInAsAdmin` de `support/admin.ts`: esos dos dan por
 * hecho el español porque el E2E habitual (`playwright.config.ts`) solo corre
 * un idioma de navegador. Aqui `playwright.screenshots.config.ts` declara DOS
 * proyectos con `locale: 'es-ES'`/`'en-US'`, y `main.ts` resuelve el idioma de
 * `/login` -pantalla PUBLICA, sin sesion todavia- con
 * `resolveLocale(navigator.languages)`: en el proyecto `en` esa pantalla sale
 * en ingles antes de que exista ninguna cuenta que lo diga. Reutilizar el
 * texto español habria colgado cada prueba del proyecto `en` esperando una
 * etiqueta que no esta.
 */
async function login(page: Page, dict: Dictionary, email: string): Promise<void> {
  await page.goto('/login')
  await page.getByLabel(dict.emailLabel).fill(email)
  await page.getByLabel(dict.passwordLabel).fill('una-contraseña-larga-y-valida')
  await page.getByRole('button', { name: dict.signInButton }).click()
  await page.waitForURL('**/employees')
}

/**
 * El sello de version (misma decision que en `setup-wizard.screenshots.ts`):
 * copia literal de `VERSION`, para que `ClientDocumentationTest` exija
 * regenerar las capturas en cada version menor.
 */
test.afterAll(() => {
  const version = readFileSync(join(REPO_ROOT, 'VERSION'))

  writeFileSync(join(IMG_ROOT, 'VERSION'), version)
})

// --- rrhh-01: listado de la plantilla ---------------------------------------

test('genera el listado de la plantilla', async ({ page }, testInfo) => {
  const dict = DICTIONARIES[localeOf(testInfo)]

  await stubManagementApi(page, { locale: localeOf(testInfo) })
  await login(page, dict, USER.email)
  await expect(page.getByRole('table')).toBeVisible()

  await shoot(page, testInfo, 'rrhh-01-empleados')
})

// --- rrhh-02 y rrhh-03: alta de un empleado y el PIN que se ve una sola vez -

test('genera el alta de un empleado y el PIN que se ve una sola vez', async ({
  page,
}, testInfo) => {
  const dict = DICTIONARIES[localeOf(testInfo)]

  await stubManagementApi(page, { locale: localeOf(testInfo) })
  await login(page, dict, USER.email)

  await page.getByRole('button', { name: dict.createAction }).click()

  const createDialog = page.getByRole('dialog', { name: dict.createHeading })

  await expect(createDialog).toBeVisible()
  await createDialog.getByLabel(dict.departmentLabel).selectOption({ label: RECEPCION })
  await createDialog.getByLabel(dict.firstNameLabel).fill('Youssef')
  await createDialog.getByLabel(dict.lastNameLabel).fill('Amrani')
  await createDialog.getByLabel(dict.hiredAtLabel).fill('2026-08-14')

  await shoot(page, testInfo, 'rrhh-02-alta-empleado', { fullPage: false })

  await createDialog.getByRole('button', { name: dict.createSubmit }).click()

  const pinDialog = page.getByRole('dialog', { name: dict.pinRevealHeading })

  await expect(pinDialog).toBeVisible()
  await expect(pinDialog.getByTestId('pin-value')).toBeVisible()

  await shoot(page, testInfo, 'rrhh-03-pin-una-vez', { fullPage: false })
})

// --- rrhh-04: ficha de un empleado, con su credencial y su PIN -------------

test('genera la ficha de un empleado con su credencial y su PIN', async ({ page }, testInfo) => {
  const dict = DICTIONARIES[localeOf(testInfo)]

  const board: CredentialStatusBoard = {
    data: [
      {
        employee_uuid: EMPLOYEE_UUID,
        employee_code: 'E7QK2MXPR',
        full_name: YOUSSEF,
        department_name: RECEPCION,
        status: 'delivered',
        credential: {
          uuid: '0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c05',
          employee_uuid: EMPLOYEE_UUID,
          key_id: 'a1',
          issued_at: '2026-08-14T06:00:00.000000Z',
          printed_at: '2026-08-14T09:05:00.000000Z',
          delivered_at: '2026-08-14T09:10:00.000000Z',
          revoked_at: null,
          revoked_reason: null,
          status: 'active',
        },
      },
    ],
    summary: {
      employees: 1,
      pending_print: 0,
      without_delivered_credential: 0,
      retiring_key_id: null,
      pending_reprint: 0,
      active_unknown_key: 0,
    },
  }

  await stubManagementApi(page, { credentialBoard: board, locale: localeOf(testInfo) })
  await login(page, dict, USER.email)
  await page.goto(`/employees/${EMPLOYEE_UUID}`)

  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  await expect(page.getByRole('heading', { name: dict.credentialHeadingInDetail })).toBeVisible()

  await shoot(page, testInfo, 'rrhh-04-ficha-empleado')
})

// --- rrhh-05: tablero de credenciales, con la hoja de instrucciones --------

test('genera el tablero de credenciales con la hoja de instrucciones', async ({
  page,
}, testInfo) => {
  const dict = DICTIONARIES[localeOf(testInfo)]

  const board: CredentialStatusBoard = {
    data: [
      {
        employee_uuid: EMPLOYEE_UUID,
        employee_code: 'E7QK2MXPR',
        full_name: YOUSSEF,
        department_name: RECEPCION,
        status: 'pending_delivery',
        credential: {
          uuid: '0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c06',
          employee_uuid: EMPLOYEE_UUID,
          key_id: 'a1',
          issued_at: '2026-09-01T06:00:00.000000Z',
          printed_at: '2026-09-01T09:00:00.000000Z',
          delivered_at: null,
          revoked_at: null,
          revoked_reason: null,
          status: 'active',
        },
      },
      {
        employee_uuid: '0199f0c2-2222-7c3e-9b21-4d5e6f7a8b91',
        employee_code: 'E4M9QX2K7B',
        full_name: LUCIA,
        department_name: PISOS,
        status: 'delivered',
        credential: {
          uuid: '0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c07',
          employee_uuid: '0199f0c2-2222-7c3e-9b21-4d5e6f7a8b91',
          key_id: 'a1',
          issued_at: '2026-08-20T06:00:00.000000Z',
          printed_at: '2026-08-20T09:00:00.000000Z',
          delivered_at: '2026-08-21T07:40:00.000000Z',
          revoked_at: null,
          revoked_reason: null,
          status: 'active',
        },
      },
    ],
    summary: {
      employees: 2,
      pending_print: 0,
      without_delivered_credential: 1,
      retiring_key_id: null,
      pending_reprint: 0,
      active_unknown_key: 0,
    },
  }

  await stubManagementApi(page, { credentialBoard: board, locale: localeOf(testInfo) })
  await login(page, dict, USER.email)
  await page.goto('/credentials')

  await expect(page.getByRole('heading', { level: 1, name: dict.credentialsHeading })).toBeVisible()
  await expect(page.getByRole('region', { name: dict.instructionsSheetHeading })).toBeVisible()

  await shoot(page, testInfo, 'rrhh-05-credenciales')
})

// --- rrhh-06: presencia en vivo ---------------------------------------------

test('genera la presencia en vivo', async ({ page }, testInfo) => {
  const dict = DICTIONARIES[localeOf(testInfo)]

  await stubManagementApi(page, { locale: localeOf(testInfo) })
  await login(page, dict, USER.email)
  await page.goto('/live')

  await expect(page.getByRole('heading', { level: 1, name: dict.liveHeading })).toBeVisible()
  await expect(page.getByRole('table')).toBeVisible()

  await shoot(page, testInfo, 'rrhh-06-presencia')
})

// --- rrhh-07 y rrhh-09: jornadas y tramos, e historial de correcciones -----

test('genera las jornadas y tramos, y el historial de correcciones', async ({ page }, testInfo) => {
  const dict = DICTIONARIES[localeOf(testInfo)]

  await stubManagementApi(page, { locale: localeOf(testInfo) })
  await login(page, dict, USER.email)
  await page.goto(`/employees/${EMPLOYEE_UUID}/workdays`)

  await expect(page.getByRole('heading', { level: 1, name: dict.workdaysHeading })).toBeVisible()
  await expect(page.getByTestId('workday')).toBeVisible()

  await shoot(page, testInfo, 'rrhh-07-jornada')

  // El historial de correcciones (RN-13): el valor anterior se conserva. La
  // correccion en si (rrhh-08) vive en su propia prueba, mas abajo, sobre el
  // mismo doble: abrirla aqui dejaria el tramo ya corregido y el «antes» del
  // historial dejaria de coincidir con lo que enseña rrhh-07.
  const historyHeading = page.getByRole('heading', { level: 3, name: dict.historyHeading })
  const historySection = historyHeading.locator('xpath=..')

  await historySection.scrollIntoViewIfNeeded()
  await expect(historySection.getByTestId('correction')).toBeVisible()

  const outDir = join(IMG_ROOT, localeOf(testInfo))

  mkdirSync(outDir, { recursive: true })
  await historySection.screenshot({
    path: join(outDir, 'rrhh-09-historial-correcciones.png'),
    animations: 'disabled',
  })
})

// --- rrhh-08: corregir las horas de un tramo (RF-PA-04) ---------------------

test('genera el dialogo de corregir las horas de un tramo', async ({ page }, testInfo) => {
  const dict = DICTIONARIES[localeOf(testInfo)]

  await stubManagementApi(page, { locale: localeOf(testInfo) })
  await login(page, dict, USER.email)
  await page.goto(`/employees/${EMPLOYEE_UUID}/workdays`)

  await expect(page.getByTestId('workday')).toBeVisible()
  await page.getByRole('button', { name: dict.correctButton }).click()

  const dialog = page.getByRole('dialog', { name: dict.correctButton })

  await expect(dialog).toBeVisible()
  await dialog.getByLabel(dict.reasonLabel).selectOption('OTROS')
  await dialog
    .getByLabel(dict.reasonOtherLabel)
    .fill(
      localeOf(testInfo) === 'es'
        ? 'Cambio de turno pactado con la compañera de tarde, confirmado con el jefe de sala.'
        : 'Shift swap agreed with the afternoon colleague, confirmed with the floor manager.',
    )

  await shoot(page, testInfo, 'rrhh-08-correccion', { fullPage: false })
})

// --- rrhh-10 y rrhh-11: bandeja de incidencias y resolver una incidencia ---

test('genera la bandeja de incidencias y el dialogo de resolver', async ({ page }, testInfo) => {
  const dict = DICTIONARIES[localeOf(testInfo)]

  await stubManagementApi(page, { locale: localeOf(testInfo) })
  await login(page, dict, USER.email)
  await page.goto('/incidents')

  await expect(page.getByRole('heading', { level: 1, name: dict.incidentsHeading })).toBeVisible()
  await expect(page.getByTestId('incident-row').first()).toBeVisible()

  await shoot(page, testInfo, 'rrhh-10-incidencias')

  await page.getByRole('button', { name: dict.resolveButton }).first().click()

  const resolveDialog = page.getByRole('dialog', { name: dict.resolveDialogHeading })

  await expect(resolveDialog).toBeVisible()
  await resolveDialog
    .getByLabel(/nota|note/i)
    .fill(
      localeOf(testInfo) === 'es'
        ? 'Se revisó el fichaje con la persona y el descanso fue el correcto: el aviso vino de un cambio de turno excepcional ya autorizado.'
        : 'The clocking was reviewed with the employee and the rest was correct: the alert came from an exceptional shift change already authorised.',
    )

  await shoot(page, testInfo, 'rrhh-11-resolver-incidencia', { fullPage: false })
})

// --- rrhh-12: informe de horas por periodo ----------------------------------

test('genera el informe de horas por periodo', async ({ page }, testInfo) => {
  const dict = DICTIONARIES[localeOf(testInfo)]

  await stubManagementApi(page, { locale: localeOf(testInfo) })
  await login(page, dict, USER.email)
  await page.goto('/reports')

  await expect(
    page.getByRole('heading', { level: 1, name: dict.periodReportHeading }),
  ).toBeVisible()

  await page.getByLabel(dict.fromLabel).fill('2026-03-01')
  await page.getByLabel(dict.toLabel).fill('2026-03-31')
  await page.getByRole('button', { name: dict.generateReport }).click()

  await expect(page.getByTestId('report-criteria')).toBeVisible()

  await shoot(page, testInfo, 'rrhh-12-informe-periodo')
})

// --- rrhh-13: exportacion legal para Inspeccion -----------------------------

test('genera la exportacion legal para Inspeccion', async ({ page }, testInfo) => {
  const dict = DICTIONARIES[localeOf(testInfo)]

  await stubManagementApi(page, { locale: localeOf(testInfo) })
  await login(page, dict, USER.email)
  await page.goto('/reports/legal-export')

  await expect(page.getByRole('heading', { level: 1, name: dict.legalExportHeading })).toBeVisible()

  await page.getByLabel(dict.fromLabel).fill('2026-03-01')
  await page.getByLabel(dict.toLabel).fill('2026-03-31')

  const download = page.waitForEvent('download')

  await page.getByRole('button', { name: dict.legalExportSubmit }).click()
  await download

  // El mismo texto vive dos veces a proposito: la region viva `role="status"`
  // que lo anuncia (`sr-only`, WCAG 4.1.3) y el parrafo visible dentro de
  // `#main`. Se comprueba el segundo, que es el que sale en la captura.
  await expect(page.locator('#main').getByText(dict.legalExportDone)).toBeVisible()

  await shoot(page, testInfo, 'rrhh-13-exportacion-legal')
})

// --- rrhh-14: perfil de cumplimiento (solo administracion) ------------------

test('genera el perfil de cumplimiento', async ({ page }, testInfo) => {
  const dict = DICTIONARIES[localeOf(testInfo)]

  await stubManagementApi(page, {
    role: 'admin',
    complianceProfile: COMPLIANCE_PROFILE,
    locale: localeOf(testInfo),
  })
  await login(page, dict, ADMIN_USER.email)
  await page.goto('/compliance-profile')

  await expect(page.getByRole('heading', { level: 1, name: dict.complianceHeading })).toBeVisible()

  await shoot(page, testInfo, 'rrhh-14-perfil-cumplimiento')
})
