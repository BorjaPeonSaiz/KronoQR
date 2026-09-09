// Accesibilidad automatizada del panel con axe-core (doc 02 §9.2 y §9.4;
// WCAG 2.2 AA, doc 01 §6.5). Mismo criterio que en el quiosco: CERO
// violaciones criticas o graves en cada pantalla del recorrido de la Fase 1.
// Las de impacto menor se listan en la salida para que se vean, pero no
// bloquean.
//
// Hasta ahora el panel solo tenia una comprobacion estructural en Vitest; esto
// es el analisis de verdad, sobre el DOM real del build.

import AxeBuilder from '@axe-core/playwright'
import type { Page } from '@playwright/test'
import { expect, test } from '@playwright/test'
import {
  DATA_EXPORT_RUNNING,
  DATA_EXPORT_UUID,
  EMPLOYEE_UUID,
  HOTEL_BRANDING,
  logIn,
  logInAsAdmin,
  stubManagementApi,
  USER,
} from './support/admin'
import { stubErrorEventsApi } from './support/errors'
import { stubOnboardingApi } from './support/setupWizard'

/** Etiquetas WCAG que se comprueban: A y AA hasta la 2.2 (doc 01 §6.5). */
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

async function expectNoBlockingViolations(page: Page, exclude: string[] = []): Promise<void> {
  const builder = new AxeBuilder({ page }).withTags(WCAG_TAGS)

  for (const selector of exclude) {
    builder.exclude(selector)
  }

  const results = await builder.analyze()

  const blocking = results.violations.filter(
    (violation) => violation.impact === 'critical' || violation.impact === 'serious',
  )

  expect(
    blocking,
    blocking.map((violation) => `${violation.id}: ${violation.help}`).join('\n'),
  ).toEqual([])
}

test.beforeEach(async ({ page }) => {
  await stubManagementApi(page)
})

test(
  'el acceso no tiene violaciones criticas ni graves',
  { tag: ['@RF-ID-01'] },
  async ({ page }) => {
    await page.goto('/login')
    await expect(page.getByRole('heading', { name: 'Acceso al panel de gestión' })).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test('la plantilla tampoco', { tag: ['@RF-GP-01'] }, async ({ page }) => {
  await logIn(page)
  await expect(page.getByRole('table')).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('la ficha de una persona tampoco', { tag: ['@RF-GP-01'] }, async ({ page }) => {
  await logIn(page)
  await page.goto(`/employees/${EMPLOYEE_UUID}`)
  await expect(page.getByRole('heading', { level: 1, name: 'Youssef Amrani' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('el registro horario tampoco', { tag: ['@RF-PA-03'] }, async ({ page }) => {
  await logIn(page)
  await page.goto(`/employees/${EMPLOYEE_UUID}/workdays`)
  await expect(page.getByTestId('workday')).toHaveCount(1)

  await expectNoBlockingViolations(page)
})

test(
  'el dialogo de corregir un tramo tampoco, con el foco dentro',
  { tag: ['@RF-PA-04'] },
  async ({ page }) => {
    await logIn(page)
    await page.goto(`/employees/${EMPLOYEE_UUID}/workdays`)
    await page.getByTestId('entry-correct').click()
    await expect(page.getByRole('dialog')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'la pantalla del codigo de segundo factor tampoco',
  { tag: ['@RF-ID-01', '@RS-06'] },
  async ({ page }) => {
    await stubManagementApi(page, { twoFactor: 'verify' })

    await page.goto('/login')
    await page.getByLabel(/Correo electrónico/).fill(USER.email)
    await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
    await page.getByRole('button', { name: 'Entrar' }).click()

    await expect(page.getByLabel(/Código de verificación/)).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'la pantalla de alta del segundo factor, con el QR, tampoco',
  { tag: ['@RF-ID-01', '@RS-06'] },
  async ({ page }) => {
    await stubManagementApi(page, { twoFactor: 'enrol' })

    await page.goto('/login')
    await page.getByLabel(/Correo electrónico/).fill(USER.email)
    await page.getByLabel(/Contraseña/).fill('una-contraseña-larga-y-valida')
    await page.getByRole('button', { name: 'Entrar' }).click()

    await expect(page.getByTestId('two-factor-secret')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test('la presencia en vivo tampoco', { tag: ['@RF-PA-01'] }, async ({ page }) => {
  await logIn(page)
  await page.goto('/live')
  await expect(page.getByTestId('presence-entry').first()).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('la bandeja de incidencias tampoco', { tag: ['@RF-PA-05'] }, async ({ page }) => {
  await logIn(page)
  await page.goto('/incidents')
  await expect(page.getByTestId('incident-row').first()).toBeVisible()

  await expectNoBlockingViolations(page)
})

test(
  'el dialogo de resolver una incidencia tampoco, con el foco dentro',
  { tag: ['@RF-PA-05'] },
  async ({ page }) => {
    await logIn(page)
    await page.goto('/incidents')
    await page.getByTestId('resolve-button').click()
    await expect(page.getByRole('dialog')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test('la pantalla de quioscos tampoco', { tag: ['@RF-PD-06'] }, async ({ page }) => {
  await stubManagementApi(page, { role: 'admin' })
  await logInAsAdmin(page)
  await page.goto('/devices')
  await expect(page.getByRole('table')).toBeVisible()

  await expectNoBlockingViolations(page)
})

test(
  'el dialogo de vincular un quiosco tampoco, con el foco dentro',
  { tag: ['@RF-PD-06'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)
    await page.goto('/devices')
    await page.getByRole('button', { name: 'Vincular quiosco' }).click()
    await expect(page.getByRole('dialog')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

// --- Marca de la instalacion (RF-PD-08, tarea 5.8) --------------------------

test('la pantalla de marca tampoco', { tag: ['@RF-PD-08', '@RQ-04'] }, async ({ page }) => {
  await stubManagementApi(page, { role: 'admin' })
  await logInAsAdmin(page)
  await page.goto('/branding')
  await expect(page.getByRole('heading', { level: 1, name: 'Marca' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test(
  'la pantalla de marca con el aviso de contraste visible tampoco',
  { tag: ['@RF-PD-08', '@RQ-04'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)
    await page.goto('/branding')
    await page.getByLabel('Color de acento', { exact: true }).fill('#f5e663')
    await expect(page.getByTestId('contrast-warnings')).toBeVisible()

    // La previsualizacion es DELIBERADAMENTE fiel al color escrito, y ese es
    // justo el punto: con un acento que no llega al minimo, el propio texto de
    // muestra («Enlace de marca») queda tan ilegible como quedaria en la
    // aplicacion real si se guardara (doc 06 §7, «se avisa, no se impone»). Un
    // violacion de contraste ahi es la prueba de que el aviso dice la verdad,
    // no un fallo de esta pantalla: se excluye de axe y se confia en el aviso
    // textual (`contrast-warnings`, ya comprobado arriba) para transmitirlo de
    // forma accesible.
    await expectNoBlockingViolations(page, ['[data-test="preview"]'])
  },
)

test(
  'el panel con una marca de cliente aplicada tampoco',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubManagementApi(page, { branding: HOTEL_BRANDING })
    await logIn(page)
    // Con logotipo, la cabecera lo enseña con el nombre como alternativa
    // textual: no hay un segundo texto suelto que lo repita.
    await expect(page.getByRole('banner').locator('img')).toHaveAttribute('alt', 'Hotel Marina')
    await expect(page).toHaveTitle('Hotel Marina')

    await expectNoBlockingViolations(page)
  },
)

// --- Soporte: paquete de diagnostico y accesos temporales (RF-PD-09,
// RF-PD-11, tarea 5.9) --------------------------------------------------------

test('la pantalla de soporte tampoco', { tag: ['@RF-PD-09', '@RF-PD-11'] }, async ({ page }) => {
  await stubManagementApi(page, { role: 'admin' })
  await logInAsAdmin(page)
  await page.goto('/support')
  await expect(page.getByRole('heading', { level: 1, name: 'Soporte' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test(
  'con el aviso de datos personales desplegado tampoco',
  { tag: ['@RF-PD-09', '@RL-19'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)
    await page.goto('/support')
    await page.getByTestId('include-personal-data').check()
    await expect(page.getByTestId('personal-data-warning')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'con el token del acceso concedido mostrado tampoco',
  { tag: ['@RF-PD-11'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)
    await page.goto('/support')
    await page.getByLabel('Motivo').fill('Incidencia #123: la cola no vacía')
    await page.getByRole('button', { name: 'Conceder acceso' }).click()
    await expect(page.getByTestId('issued-token')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

// --- Historico de errores agrupado por huella (RF-PD-15, tarea 5.12) -------

test('la pantalla de errores tampoco', { tag: ['@RF-PD-15'] }, async ({ page }) => {
  await stubManagementApi(page, { role: 'admin' })
  await stubErrorEventsApi(page)
  await logInAsAdmin(page)
  await page.goto('/errors')
  await expect(page.getByTestId('error-row')).toBeVisible()

  await expectNoBlockingViolations(page)
})

test(
  'la fila expandida del historico de errores tampoco',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await stubErrorEventsApi(page)
    await logInAsAdmin(page)
    await page.goto('/errors')
    await page.getByTestId('toggle-87').click()
    await expect(page.getByTestId('what-to-do')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'el dialogo de marcar un error como resuelto tampoco, con el foco dentro',
  { tag: ['@RF-PD-15'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await stubErrorEventsApi(page)
    await logInAsAdmin(page)
    await page.goto('/errors')
    await page.getByTestId('resolve-87').click()
    await expect(page.getByRole('dialog')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

// --- Exportación íntegra de datos (RF-PD-14, RL-20, tarea 5.10) -------------

test(
  'la pantalla de licencia con «Tus datos son tuyos» visible tampoco',
  { tag: ['@RF-PD-14', '@RL-20'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin', dataExports: [DATA_EXPORT_RUNNING] })
    await logInAsAdmin(page)
    await page.goto('/license')
    await expect(page.getByRole('heading', { level: 2, name: 'Tus datos son tuyos' })).toBeVisible()
    await expect(page.getByTestId(`status-${DATA_EXPORT_UUID}`)).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'el diálogo de generar la exportación tampoco, con el foco dentro y el aviso legible',
  { tag: ['@RF-PD-14', '@RL-20'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)
    await page.goto('/license')
    await page.getByTestId('open-generate').click()
    await expect(page.getByRole('dialog')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

// --- Asistente de puesta en marcha (RF-PD-03, RQ-04, tarea 5.5) -------------
//
// Es la PRIMERA pantalla del producto: cero violaciones criticas o graves en
// CADA paso, no solo en el asistente en general. Cada prueba aterriza
// directamente en el paso que comprueba —con el estado de los pasos previos
// ya resuelto en el doble— para no repetir el recorrido completo ocho veces.

test(
  'el paso del primer administrador no tiene violaciones',
  { tag: ['@RQ-04'] },
  async ({ page }) => {
    await stubOnboardingApi(page)
    await page.goto('/setup')
    await expect(page.getByRole('heading', { name: 'Primer administrador' })).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'el alta del segundo factor del primer administrador tampoco, con el QR',
  { tag: ['@RQ-04'] },
  async ({ page }) => {
    await stubOnboardingApi(page)
    await page.goto('/setup')
    await page.getByLabel('Nombre').fill('Dirección del hotel')
    await page.getByLabel('Correo electrónico').fill('direccion@hotel.example')
    await page.getByLabel('Contraseña').fill('una-contrasena-larga-y-propia-1!')
    await page.getByRole('button', { name: 'Crear la cuenta' }).click()
    await expect(page.getByTestId('two-factor-secret')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test('el paso de organizacion no tiene violaciones', { tag: ['@RQ-04'] }, async ({ page }) => {
  await stubOnboardingApi(page, { administratorAlreadyDone: true })
  await page.goto('/setup')
  await expect(page.getByRole('heading', { name: 'Organización' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('el paso del centro de trabajo tampoco', { tag: ['@RQ-04'] }, async ({ page }) => {
  await stubOnboardingApi(page, {
    administratorAlreadyDone: true,
    stepsDone: { organisation: 'completed' },
  })
  await page.goto('/setup')
  await expect(page.getByRole('heading', { name: 'Centro de trabajo' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('el paso de departamentos tampoco', { tag: ['@RQ-04'] }, async ({ page }) => {
  await stubOnboardingApi(page, {
    administratorAlreadyDone: true,
    siteDone: true,
    stepsDone: { organisation: 'completed' },
  })
  await page.goto('/setup')
  await expect(page.getByRole('heading', { name: 'Departamentos' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test(
  'el paso del perfil de convenio tampoco, con el aviso de RL-21',
  { tag: ['@RQ-04'] },
  async ({ page }) => {
    await stubOnboardingApi(page, {
      administratorAlreadyDone: true,
      siteDone: true,
      stepsDone: { organisation: 'completed', departments: 'skipped' },
    })
    await page.goto('/setup')
    await expect(page.getByRole('heading', { name: 'Perfil de convenio' })).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'el paso de plantilla tampoco, con el informe de importacion',
  { tag: ['@RQ-04'] },
  async ({ page }) => {
    await stubOnboardingApi(page, {
      administratorAlreadyDone: true,
      siteDone: true,
      stepsDone: {
        organisation: 'completed',
        departments: 'skipped',
        compliance_profile: 'completed',
      },
    })
    await page.goto('/setup')
    await expect(page.getByRole('heading', { name: 'Plantilla' })).toBeVisible()
    await page.getByTestId('import-file').setInputFiles({
      name: 'plantilla.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from('first_name,last_name\nYoussef,Amrani\n'),
    })
    await page.getByTestId('validate').click()
    await expect(page.getByTestId('import-row-2')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'el paso de licencia tampoco, con la pantalla de activacion incrustada',
  { tag: ['@RQ-04'] },
  async ({ page }) => {
    await stubOnboardingApi(page, {
      administratorAlreadyDone: true,
      siteDone: true,
      stepsDone: {
        organisation: 'completed',
        departments: 'skipped',
        compliance_profile: 'completed',
        employees: 'skipped',
      },
    })
    await page.goto('/setup')
    await expect(page.getByRole('heading', { name: 'Licencia', level: 2 })).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test('el paso del primer quiosco tampoco', { tag: ['@RQ-04'] }, async ({ page }) => {
  await stubOnboardingApi(page, {
    administratorAlreadyDone: true,
    siteDone: true,
    stepsDone: {
      organisation: 'completed',
      departments: 'skipped',
      compliance_profile: 'completed',
      employees: 'skipped',
      license: 'skipped',
    },
  })
  await page.goto('/setup')
  await expect(page.getByRole('heading', { name: 'Primer quiosco' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('la revision final tampoco', { tag: ['@RQ-04'] }, async ({ page }) => {
  await stubOnboardingApi(page, {
    administratorAlreadyDone: true,
    siteDone: true,
    stepsDone: {
      organisation: 'completed',
      departments: 'skipped',
      compliance_profile: 'completed',
      employees: 'skipped',
      license: 'skipped',
      kiosk: 'skipped',
    },
  })
  await page.goto('/setup')
  await expect(page.getByRole('heading', { name: 'Revisa antes de terminar' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('el resumen final de cierre tampoco', { tag: ['@RQ-04'] }, async ({ page }) => {
  await stubOnboardingApi(page, {
    administratorAlreadyDone: true,
    siteDone: true,
    stepsDone: {
      organisation: 'completed',
      departments: 'skipped',
      compliance_profile: 'completed',
      employees: 'skipped',
      license: 'skipped',
      kiosk: 'skipped',
    },
  })
  await page.goto('/setup')
  await page.getByTestId('complete-setup').click()
  await expect(page.getByRole('heading', { name: 'Puesta en marcha completada' })).toBeVisible()

  await expectNoBlockingViolations(page)
})
