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
import type { Absence } from '@/shared/api/types'
import {
  DATA_EXPORT_RUNNING,
  DATA_EXPORT_UUID,
  DEVICE,
  EMPLOYEE_UUID,
  HOTEL_BRANDING,
  logIn,
  logInAsAdmin,
  REPORT_EXPORT_COMPLETED,
  REPORT_EXPORT_UUID,
  stubManagementApi,
  USER,
  WORKDAYS_WITH_BREAK,
} from './support/admin'

/** Una ausencia de ejemplo, para que la pantalla de listado no salga vacía (RF-GP-04, tarea 3.10). */
const ABSENCE_EXAMPLE: Absence = {
  uuid: '0199f8d2-0009-7a10-9c60-6d7e8f9a0b12',
  employee_uuid: EMPLOYEE_UUID,
  employee_code: 'E7QK2MXPR',
  employee_name: 'Youssef Amrani',
  department_id: 3,
  department_name: 'Recepción',
  type: 'vacation',
  starts_on: '2026-03-02',
  ends_on: '2026-03-06',
  days: 5,
  note: null,
  status: 'active',
  version: 1,
  supersedes_uuid: null,
  superseded_by_uuid: null,
  change_reason: null,
  voided_at: null,
  void_reason: null,
  created_at: '2026-02-20T09:14:02.118000Z',
}
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

test('las ausencias tampoco', { tag: ['@RF-GP-04'] }, async ({ page }) => {
  await stubManagementApi(page, { absences: [ABSENCE_EXAMPLE] })
  await logIn(page)
  await page.goto('/absences')
  await expect(page.getByRole('table')).toBeVisible()

  await expectNoBlockingViolations(page)
})

test(
  'el dialogo de registrar una ausencia tampoco, con el foco dentro',
  { tag: ['@RF-GP-04'] },
  async ({ page }) => {
    await logIn(page)
    await page.goto('/absences')
    await page.getByTestId('absences-register').click()
    await expect(page.getByRole('dialog')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

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
  'el registro horario con una pausa fichada tampoco (RF-AT-12, tarea 3.5)',
  { tag: ['@RF-PA-03', '@RF-AT-12'] },
  async ({ page }) => {
    await stubManagementApi(page, { workdays: WORKDAYS_WITH_BREAK })
    await logIn(page)
    await page.goto(`/employees/${EMPLOYEE_UUID}/workdays`)
    await expect(page.getByTestId('break-row')).toBeVisible()
    await expect(page.getByTestId('break-badge')).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

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

test('la pantalla de quioscos tampoco', { tag: ['@RF-PD-06', '@RF-PA-07'] }, async ({ page }) => {
  await stubManagementApi(page, {
    role: 'admin',
    // Con un quiosco en fallo y otro con la bateria baja: la fila resaltada,
    // el badge de veredicto y el aviso de bateria pasan por axe tambien, no
    // solo el camino sin avisos.
    devices: {
      devices: [
        DEVICE,
        {
          ...DEVICE,
          uuid: '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a82',
          name: 'Cocina',
          health: { verdict: 'failure', reason: 'silent', seconds_since_last_seen: 900 },
        },
      ],
    },
  })
  await logInAsAdmin(page)
  await page.goto('/devices')
  await expect(page.getByRole('table')).toBeVisible()

  await expectNoBlockingViolations(page)
})

test(
  'el dialogo de vincular un quiosco tampoco, con el foco dentro',
  { tag: ['@RF-PD-06', '@RF-PA-07'] },
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

test('la pantalla de marca tampoco', { tag: ['@RF-PD-08'] }, async ({ page }) => {
  await stubManagementApi(page, { role: 'admin' })
  await logInAsAdmin(page)
  await page.goto('/branding')
  await expect(page.getByRole('heading', { level: 1, name: 'Marca' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test(
  'la pantalla de marca con el aviso de contraste visible tampoco',
  { tag: ['@RF-PD-08'] },
  async ({ page }) => {
    await stubManagementApi(page, { role: 'admin' })
    await logInAsAdmin(page)
    await page.goto('/branding')
    await page.getByLabel('Color de acento', { exact: true }).fill('#f5e663')
    await expect(page.getByTestId('accent-contrast')).toContainText('No llega al mínimo')
    await expect(page.getByTestId('accent-confirm')).toBeVisible()

    // Desde MB1 la previsualizacion enseña el tono OSCURECIDO que se aplicara de
    // verdad, asi que ya no hay nada que excluir de axe: ni el aviso ni la
    // muestra tienen violaciones de contraste.
    await expectNoBlockingViolations(page)
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

// --- Perfil de cumplimiento (RF-PD-07, tarea 5.2) ---------------------------

test('la pantalla de cumplimiento tampoco', { tag: ['@RF-PD-07'] }, async ({ page }) => {
  await stubManagementApi(page, { role: 'admin' })
  await logInAsAdmin(page)
  await page.goto('/compliance-profile')
  await expect(
    page.getByRole('heading', { level: 1, name: 'Perfil de cumplimiento' }),
  ).toBeVisible()

  await expectNoBlockingViolations(page)
})

// --- Vista de cumplimiento (RF-PA-06, tarea 3.4) -----------------------------

test('la vista de cumplimiento tampoco', { tag: ['@RF-PA-06'] }, async ({ page }) => {
  await logIn(page)
  await page.goto('/compliance')
  await expect(page.getByRole('heading', { level: 1, name: 'Cumplimiento' })).toBeVisible()
  await expect(page.getByTestId('compliance-finding-row').first()).toBeVisible()

  await expectNoBlockingViolations(page)
})

// --- Credenciales (RF-QR-*, tarea 1.9/1.10) ---------------------------------

test('la pantalla de credenciales tampoco', { tag: ['@RF-QR-07'] }, async ({ page }) => {
  await logIn(page)
  await page.goto('/credentials')
  await expect(page.getByRole('heading', { level: 1, name: 'Credenciales' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

// --- Informes: horas por periodo y salida a nomina (RF-IN-06, RF-IN-07,
// tarea 3.9) -------------------------------------------------------------------

test(
  'el informe de horas por periodo y su bloque de exportaciones tampoco',
  { tag: ['@RF-IN-06'] },
  async ({ page }) => {
    await stubManagementApi(page, { reportExports: [REPORT_EXPORT_COMPLETED] })
    await logIn(page)
    await page.goto('/reports')
    await expect(
      page.getByRole('heading', { level: 1, name: 'Informe de horas por periodo' }),
    ).toBeVisible()
    // El bloque de exportaciones en segundo plano esta SIEMPRE visible en
    // esta pantalla, con una fila ya completada para que la tabla entera
    // -estados, descarga, criterios desplegables- entre en el analisis.
    await expect(page.getByTestId(`status-${REPORT_EXPORT_UUID}`)).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

// --- Cuadro de impacto y adopcion (RF-IN-08, tarea 3.13) --------------------

test(
  'el cuadro de impacto y adopción tampoco, con los graficos',
  { tag: ['@RF-IN-08'] },
  async ({ page }) => {
    await stubManagementApi(page)
    await logIn(page)
    await page.goto('/reports/adoption')
    await expect(page.getByRole('heading', { level: 1, name: 'Impacto y adopción' })).toBeVisible()
    await expect(page.getByTestId('indicator-card')).toHaveCount(6)

    await expectNoBlockingViolations(page)
  },
)

test(
  'el cuadro de impacto y adopción con la tabla de datos alternativa tampoco',
  { tag: ['@RF-IN-08'] },
  async ({ page }) => {
    await stubManagementApi(page)
    await logIn(page)
    await page.goto('/reports/adoption')
    await expect(page.getByTestId('indicator-card')).toHaveCount(6)

    // Los dos graficos conmutados a tabla (doc 02 §3.3): es lo que hace el
    // cuadro accesible, y tiene que pasar axe igual que el grafico.
    const toggles = page.getByTestId('toggle-view')

    await expect(toggles).toHaveCount(2)
    await toggles.nth(0).click()
    await toggles.nth(1).click()
    await expect(page.getByTestId('chart-table')).toHaveCount(2)

    await expectNoBlockingViolations(page)
  },
)

test('la salida a nomina tampoco', { tag: ['@RF-IN-07'] }, async ({ page }) => {
  await stubManagementApi(page, { role: 'admin' })
  await logInAsAdmin(page)
  await page.goto('/reports/payroll')
  await expect(page.getByRole('heading', { level: 1, name: 'Salida a nómina' })).toBeVisible()
  await expect(page.getByTestId('payroll-columns-preview')).toBeVisible()

  await expectNoBlockingViolations(page)
})

// --- Ajustes operativos (RF-PD-01, tarea 5.13) ------------------------------

test('la pantalla de ajustes operativos tampoco', { tag: ['@RF-PD-01'] }, async ({ page }) => {
  await stubManagementApi(page, { role: 'admin' })
  await logInAsAdmin(page)
  await page.goto('/settings')
  await expect(page.getByRole('heading', { level: 1, name: 'Ajustes operativos' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

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

// --- Asistente de puesta en marcha (RF-PD-03, tarea 5.5) --------------------
//
// Es la PRIMERA pantalla del producto: cero violaciones criticas o graves en
// CADA paso, no solo en el asistente en general. Cada prueba aterriza
// directamente en el paso que comprueba —con el estado de los pasos previos
// ya resuelto en el doble— para no repetir el recorrido completo ocho veces.

test(
  'el paso del primer administrador no tiene violaciones',
  { tag: ['@RF-PD-03'] },
  async ({ page }) => {
    await stubOnboardingApi(page)
    await page.goto('/setup')
    await expect(page.getByRole('heading', { name: 'Primer administrador' })).toBeVisible()

    await expectNoBlockingViolations(page)
  },
)

test(
  'el alta del segundo factor del primer administrador tampoco, con el QR',
  { tag: ['@RF-PD-03'] },
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

test('el paso de organizacion no tiene violaciones', { tag: ['@RF-PD-03'] }, async ({ page }) => {
  await stubOnboardingApi(page, { administratorAlreadyDone: true })
  await page.goto('/setup')
  await expect(page.getByRole('heading', { name: 'Organización' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('el paso del centro de trabajo tampoco', { tag: ['@RF-PD-03'] }, async ({ page }) => {
  await stubOnboardingApi(page, {
    administratorAlreadyDone: true,
    stepsDone: { organisation: 'completed' },
  })
  await page.goto('/setup')
  await expect(page.getByRole('heading', { name: 'Centro de trabajo' })).toBeVisible()

  await expectNoBlockingViolations(page)
})

test('el paso de departamentos tampoco', { tag: ['@RF-PD-03'] }, async ({ page }) => {
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
  { tag: ['@RF-PD-03'] },
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
  { tag: ['@RF-PD-03'] },
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
  { tag: ['@RF-PD-03'] },
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

test('el paso del primer quiosco tampoco', { tag: ['@RF-PD-03'] }, async ({ page }) => {
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

test('la revision final tampoco', { tag: ['@RF-PD-03'] }, async ({ page }) => {
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

test('el resumen final de cierre tampoco', { tag: ['@RF-PD-03'] }, async ({ page }) => {
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

// --- Bloque 9 de las correcciones 2.2.0: contratos, tablas con scroll, foco ---

/**
 * Las anchuras a las que se analiza el panel (AX7-03): el portatil de RRHH, un
 * monitor pequeño y una tablet. Las tablas desbordan justo en las dos ultimas,
 * y `scrollable-region-focusable` solo se dispara cuando hay desbordamiento.
 */
const VIEWPORT_WIDTHS = [1366, 1024, 768] as const

async function generatePeriodReport(page: Page): Promise<void> {
  await page.goto('/reports')
  await page.getByLabel('Desde').fill('2026-03-01')
  await page.getByLabel('Hasta').fill('2026-03-31')
  await page.getByRole('button', { name: 'Generar informe' }).click()
  await expect(page.getByTestId('report-row').first()).toBeVisible()
}

for (const width of VIEWPORT_WIDTHS) {
  test(
    `el informe de periodo YA GENERADO tampoco, a ${width} px (AX7-01, AX7-03)`,
    { tag: ['@RF-IN-01', '@RF-IN-03'] },
    async ({ page }) => {
      await page.setViewportSize({ width, height: 768 })
      await logIn(page)
      await generatePeriodReport(page)

      // La tabla desplazable es una region con nombre y se alcanza con el teclado.
      const region = page.getByRole('region', { name: /Horas por periodo/ })

      await expect(region).toHaveAttribute('tabindex', '0')

      await expectNoBlockingViolations(page)
    },
  )

  test(
    `la presencia en vivo y la bandeja de incidencias tampoco, a ${width} px (AX7-02, AX7-03)`,
    { tag: ['@RF-PA-01', '@RF-PA-05'] },
    async ({ page }) => {
      await page.setViewportSize({ width, height: 768 })
      await logIn(page)

      await page.goto('/live')
      await expect(page.getByTestId('presence-entry').first()).toBeVisible()
      await expect(page.getByTestId('presence-table')).toHaveAttribute('tabindex', '0')
      await expectNoBlockingViolations(page)

      await page.goto('/incidents')
      await expect(page.getByTestId('incident-row').first()).toBeVisible()
      await expect(page.getByTestId('incident-table')).toHaveAttribute('tabindex', '0')
      await expectNoBlockingViolations(page)
    },
  )
}

test(
  'la ficha con los contratos y el dialogo de alta tampoco, a 768 px',
  { tag: ['@RF-GP-02'] },
  async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 768 })
    await stubManagementApi(page, {
      contracts: [
        {
          id: 41,
          employee_uuid: EMPLOYEE_UUID,
          weekly_hours: 20,
          annual_hours: 1040,
          schedule_type: 'turnos',
          valid_from: '2026-01-01',
          valid_to: '2026-03-15',
          is_current: false,
        },
        {
          id: 58,
          employee_uuid: EMPLOYEE_UUID,
          weekly_hours: 40,
          annual_hours: 1780,
          schedule_type: 'turnos',
          valid_from: '2026-03-16',
          valid_to: null,
          is_current: true,
        },
      ],
    })
    await logIn(page)
    await page.goto(`/employees/${EMPLOYEE_UUID}`)
    await expect(page.getByTestId('contract-row')).toHaveCount(2)
    await expectNoBlockingViolations(page)

    await page.getByTestId('contract-register-open').click()
    await expect(page.getByRole('dialog', { name: 'Registrar contrato' })).toBeVisible()
    await expectNoBlockingViolations(page)
  },
)

test(
  'el dialogo atrapa el foco en un ciclo de Tab y lo devuelve al boton que lo abrio (PA7-002)',
  { tag: ['@RF-PA-04'] },
  async ({ page }) => {
    await logIn(page)
    await page.goto(`/employees/${EMPLOYEE_UUID}/workdays`)

    const opener = page.getByTestId('entry-correct')

    await opener.focus()
    await page.keyboard.press('Enter')

    // `document.activeElement` se retargetea al input aunque el foco este en un
    // segmento interno de un campo de fecha, donde `:focus` no coincide.
    const focusIsInsideDialog = (): Promise<boolean> =>
      page.evaluate(
        () => document.querySelector('[role="dialog"]')?.contains(document.activeElement) === true,
      )
    const dialog = page.getByRole('dialog')

    await expect(dialog).toBeVisible()

    // Quince Tab hacia delante y cinco hacia atras: el foco nunca sale del dialogo.
    for (let step = 0; step < 15; step += 1) {
      await page.keyboard.press('Tab')
      await expect.poll(focusIsInsideDialog).toBe(true)
    }

    for (let step = 0; step < 5; step += 1) {
      await page.keyboard.press('Shift+Tab')
      await expect.poll(focusIsInsideDialog).toBe(true)
    }

    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(opener).toBeFocused()
  },
)

test(
  'el campo de fecha y hora de una correccion muestra el anillo de foco en el propio input (PA7-004)',
  { tag: ['@RF-PA-04'] },
  async ({ page }) => {
    await logIn(page)
    await page.goto(`/employees/${EMPLOYEE_UUID}/workdays`)
    await page.getByTestId('entry-correct').click()

    const dialog = page.getByRole('dialog')
    const input = dialog.locator('input[type="datetime-local"]').first()

    await input.focus()

    // Se recorren los segmentos internos (dia, mes, año, hora, minuto) y en
    // ninguno desaparece el anillo, que era lo que fallaba en el ultimo.
    for (let segment = 0; segment < 6; segment += 1) {
      const outline = await input.evaluate((element) => {
        const style = getComputedStyle(element)

        return { style: style.outlineStyle, width: style.outlineWidth }
      })

      expect(outline.style, `segmento ${segment}`).not.toBe('none')
      expect(outline.width, `segmento ${segment}`).not.toBe('0px')
      await page.keyboard.press('Tab')

      if (!(await input.evaluate((element) => element.matches(':focus-within')))) {
        break
      }
    }
  },
)
