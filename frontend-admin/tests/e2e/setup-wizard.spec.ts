// El asistente de puesta en marcha (RF-PD-03, RF-GP-05, tarea 5.5).
//
// EL RECORRIDO QUE IMPORTA: una instalacion recien montada, sin ninguna
// cuenta ni ningun centro, hasta un panel operativo desde el que se podria
// emitir la primera tarjeta. El emparejamiento de quiosco (5.6) todavia no
// existe: el paso solo se puede omitir, y asi lo comprueba esta prueba —el
// alcance real de «hasta poder fichar» en esta version es «hasta un panel
// listo para emitir credenciales», que es lo que RF-QR-08 hace despues.
import { expect, type Page, test } from '@playwright/test'
import { SITE, stubOnboardingApi, TOTP_CODE } from './support/setupWizard'

async function createAdministrator(page: Page): Promise<void> {
  await page.getByLabel('Nombre').fill('Dirección del hotel')
  await page.getByLabel('Correo electrónico').fill('direccion@hotel.example')
  await page.getByLabel('Contraseña').fill('una-contrasena-larga-y-propia-1!')
  await page.getByRole('button', { name: 'Crear la cuenta' }).click()

  await expect(page.getByTestId('two-factor-secret')).toBeVisible()
  await page.getByLabel(/Código del autenticador/).fill(TOTP_CODE)
  await page.getByRole('button', { name: 'Activar y entrar' }).click()
}

async function fillOrganisation(page: Page): Promise<void> {
  await expect(page.getByRole('heading', { name: 'Organización' })).toBeVisible()
  await page.getByLabel('Nombre del establecimiento').fill('Hotel Marina')
  await page.getByRole('button', { name: 'Continuar' }).click()
}

async function fillSite(page: Page): Promise<void> {
  await expect(page.getByRole('heading', { name: 'Centro de trabajo' })).toBeVisible()
  await page.getByLabel('Nombre del centro').fill(SITE.name)
  // La zona horaria ya trae `Europe/Madrid` de serie.
  await page.getByRole('button', { name: 'Continuar' }).click()
}

test(
  'recorrido completo: de la instalacion vacia a un panel listo para emitir credenciales',
  { tag: ['@RF-PD-03'] },
  async ({ page }) => {
    // El panel es de un hotel; la instalacion empieza vacia (RF-PD-03).
    await stubOnboardingApi(page)
    await page.goto('/employees')

    // Detectado: sin ninguna cuenta ni ningun centro, el panel manda al
    // asistente sea cual sea la ruta pedida.
    await expect(page).toHaveURL(/\/setup$/)
    await expect(page.getByRole('heading', { name: 'Puesta en marcha' })).toBeVisible()

    // Paso 1: primer administrador, con el segundo factor obligatorio.
    await createAdministrator(page)

    // Paso 2: organizacion.
    await fillOrganisation(page)

    // Paso 3: centro de trabajo.
    await fillSite(page)

    // Paso 4: departamentos. Se da de alta uno y se continua (no se omite:
    // el camino de omitir ya lo cubre la prueba de licencia).
    await expect(page.getByRole('heading', { name: 'Departamentos' })).toBeVisible()
    await page.getByLabel('Nombre del departamento').fill('Recepción')
    await page.getByRole('button', { name: 'Añadir' }).click()
    await expect(page.getByText('Recepción')).toBeVisible()
    await page.getByTestId('continue').click()

    // Paso 5: perfil de convenio. No omitible (RL-21): se confirma sin
    // cambiar ningun umbral.
    await expect(page.getByRole('heading', { name: 'Perfil de convenio' })).toBeVisible()
    await expect(page.getByText(/contrastarlos con el convenio/)).toBeVisible()
    await page.getByTestId('confirm-compliance-profile').click()

    // Paso 6: plantilla (RF-GP-05). Simulacion, informe, y aplicacion tras
    // confirmar — nunca se aplica sin haber validado antes.
    await expect(page.getByRole('heading', { name: 'Plantilla' })).toBeVisible()
    await page.getByTestId('import-file').setInputFiles({
      name: 'plantilla.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from('first_name,last_name\nYoussef,Amrani\n'),
    })
    await page.getByTestId('validate').click()
    await expect(page.getByTestId('import-row-2')).toContainText('Youssef Amrani')
    await page.getByTestId('apply').click()
    await expect(page.getByTestId('apply')).toHaveCount(0)
    await page.getByTestId('continue').click()

    // Paso 7: licencia. Omitida: es accesoria (regla dura 15) y nunca puede
    // ser requisito para terminar la puesta en marcha.
    await expect(page.getByRole('heading', { name: 'Licencia', level: 2 })).toBeVisible()
    await expect(page.getByText(/NO dependen de la licencia/)).toBeVisible()
    await page.getByTestId('skip').click()

    // Paso 8: primer quiosco. El emparejamiento es de la 5.6: solo se puede
    // omitir, con el procedimiento manual explicado.
    await expect(page.getByRole('heading', { name: 'Primer quiosco' })).toBeVisible()
    await expect(
      page.getByText(/Vincular un quiosco llegará en una versión posterior/),
    ).toBeVisible()
    await page.getByTestId('skip').click()

    // Revision final: los ocho pasos, con sus estados, y el cierre explicito.
    await expect(page.getByRole('heading', { name: 'Revisa antes de terminar' })).toBeVisible()
    const reviewItems = page.getByTestId('review-list').locator('li')
    await expect(reviewItems).toHaveCount(8)
    await expect(page.getByTestId('review-license')).toContainText('Omitido')
    await expect(page.getByTestId('review-compliance_profile')).toContainText('Hecho')
    await page.getByTestId('complete-setup').click()

    // Resumen final accionable: la cifra de tarjetas pendientes, arriba, con
    // el enlace al panel de estado de credenciales y el comando de consola.
    await expect(page.getByRole('heading', { name: 'Puesta en marcha completada' })).toBeVisible()
    await expect(page.getByTestId('credentials-alert')).toContainText('1')
    await expect(page.getByTestId('credentials-alert')).toContainText(
      'credentials:status --pending',
    )
    await expect(
      page.getByRole('link', { name: 'Ir al panel de estado de credenciales' }),
    ).toBeVisible()

    // Y desde ahi, el panel operativo de verdad: el asistente ya no vuelve a
    // abrirse (es de un solo uso).
    await page.getByTestId('go-to-panel').click()
    await expect(page).toHaveURL(/\/employees$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Plantilla' })).toBeVisible()

    await page.goto('/setup')
    await expect(page).toHaveURL(/\/login$/)
  },
)

test(
  'la licencia se puede activar dentro del asistente, sin omitirla',
  { tag: ['@RF-PD-03', '@RF-PD-04'] },
  async ({ page }) => {
    await stubOnboardingApi(page)
    await page.goto('/setup')

    await createAdministrator(page)
    await fillOrganisation(page)
    await fillSite(page)

    await expect(page.getByRole('heading', { name: 'Departamentos' })).toBeVisible()
    await page.getByTestId('skip').click()

    await expect(page.getByRole('heading', { name: 'Perfil de convenio' })).toBeVisible()
    await page.getByTestId('confirm-compliance-profile').click()

    await expect(page.getByRole('heading', { name: 'Plantilla' })).toBeVisible()
    await page.getByTestId('skip').click()

    await expect(page.getByRole('heading', { name: 'Licencia', level: 2 })).toBeVisible()
    await page.getByLabel('Clave de licencia').fill('KQL1.carga.firma')
    await page.getByRole('button', { name: 'Activar una clave' }).click()
    await expect(page.getByTestId('activated')).toBeVisible()
    // El estado ya no es «sin licencia activada»: la clave activada rige.
    await expect(page.getByText('Licencia vigente')).toBeVisible()
    await page.getByTestId('continue').click()

    await expect(page.getByRole('heading', { name: 'Primer quiosco' })).toBeVisible()
    await page.getByTestId('skip').click()

    await page.getByTestId('complete-setup').click()
    await expect(page.getByTestId('review-license')).not.toBeVisible()
    await expect(page.getByRole('heading', { name: 'Puesta en marcha completada' })).toBeVisible()
    await expect(page.getByTestId('summary')).toContainText('Licencia vigente')
  },
)

test(
  'abandonar a mitad y volver retoma exactamente donde se dejo (reanudable)',
  { tag: ['@RF-PD-03'] },
  async ({ page }) => {
    await stubOnboardingApi(page)
    await page.goto('/setup')

    await createAdministrator(page)
    await fillOrganisation(page)
    await fillSite(page)

    await expect(page.getByRole('heading', { name: 'Departamentos' })).toBeVisible()

    // Se abandona aqui: recarga completa, como quien cierra la pestaña y
    // vuelve otro dia. El estado vive en el servidor, no en el navegador.
    await page.reload()

    await expect(page.getByRole('heading', { name: 'Departamentos' })).toBeVisible()
    // Los tres pasos anteriores siguen resueltos: no se piden otra vez.
    await expect(page.getByRole('heading', { name: 'Primer administrador' })).not.toBeVisible()
  },
)

test(
  'una instalacion ya configurada no vuelve a abrir el asistente, ni siquiera visitando /setup',
  { tag: ['@RF-PD-03'] },
  async ({ page }) => {
    await stubOnboardingApi(page, { alreadyCompleted: true })

    await page.goto('/setup')

    // `available: false`: el asistente es de un solo uso (decision de la
    // 5.5) y remite al acceso normal, no a ningun paso.
    await expect(page).toHaveURL(/\/login$/)
    await expect(page.getByRole('heading', { name: 'Acceso al panel de gestión' })).toBeVisible()

    // Y el resto del panel funciona con total normalidad: no hay ningun
    // redirigido de vuelta al asistente.
    await page.goto('/employees')
    await expect(page).toHaveURL(/\/login\?redirect=/)
  },
)
