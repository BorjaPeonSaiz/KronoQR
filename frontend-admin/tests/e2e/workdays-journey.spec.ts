// El recorrido de RRHH que la Fase 1 dejo sin E2E (§9.5: «recorrido de
// usuario → E2E»): entrar, buscar a una persona en la plantilla (RF-GP-01),
// abrir su ficha y leer su registro horario con la correccion que tuvo
// (RF-PA-03, RN-13).
//
// Las horas que se comprueban son las de la ZONA DEL CENTRO (`Europe/Madrid`),
// y el navegador de esta prueba esta en `Atlantic/Canary` a proposito: si
// alguien reconvirtiera en el cliente, el turno de las 06:00 saldria a las
// 05:00 y esto fallaria (regla dura 3).

import { expect, test } from '@playwright/test'
import { EMPLOYEE, EMPLOYEE_UUID, logIn, stubManagementApi } from './support/admin'

const FULL_NAME = `${EMPLOYEE.first_name} ${EMPLOYEE.last_name}`

test(
  'de la plantilla a la ficha y de la ficha al registro horario, con la correccion visible',
  { tag: ['@RF-GP-01', '@RF-PA-03', '@RN-13'] },
  async ({ page }) => {
    await stubManagementApi(page)
    await logIn(page)

    // Plantilla: la persona aparece con su codigo, y el nombre lleva a la ficha.
    const table = page.getByRole('table')
    await expect(table).toBeVisible()
    await expect(table).toContainText(EMPLOYEE.employee_code)
    await table.getByRole('link', { name: FULL_NAME }).click()

    // Ficha: nombre, codigo y el enlace al registro horario.
    await expect(page).toHaveURL(new RegExp(`/employees/${EMPLOYEE_UUID}$`))
    await expect(page.getByRole('heading', { level: 1, name: FULL_NAME })).toBeVisible()
    await expect(page.getByText(EMPLOYEE.employee_code).first()).toBeVisible()
    await page.getByRole('link', { name: 'Ver el registro horario de esta persona' }).click()

    // Registro horario: de quien es, que periodo, y que consultarlo se audita.
    await expect(page).toHaveURL(new RegExp(`/employees/${EMPLOYEE_UUID}/workdays$`))
    await expect(page.getByTestId('person')).toContainText(FULL_NAME)
    await expect(page.getByTestId('person')).toContainText(EMPLOYEE.employee_code)
    await expect(page.getByTestId('resolved-range')).toContainText('2026-03-01')
    await expect(page.getByTestId('resolved-range')).toContainText('2026-03-31')
    await expect(page.getByText(/queda anotado en la auditoría/)).toBeVisible()

    // La jornada del 14 de marzo: un tramo 06:00 → 14:05 hora del centro, y el
    // total declarado cuadra con la suma de los tramos.
    const day = page.getByTestId('workday')
    await expect(day).toHaveCount(1)
    await expect(day.getByTestId('day-total')).toHaveText('8 h 05 min')
    await expect(day).toContainText('06:00')
    await expect(day).toContainText('14:05')
    await expect(day.getByTestId('summed-total')).toHaveText('8 h 05 min')
    await expect(day.getByTestId('totals-mismatch')).toHaveCount(0)

    // La correccion sigue ahi, con quien la firmo y por que (nada se borra).
    await expect(day.getByTestId('correction')).toHaveCount(1)
    await expect(day).toContainText('Cuenta de RRHH')
    await expect(day).toContainText('16:22')

    // Y de vuelta a la ficha por el enlace, no por el historial del navegador.
    await page.getByRole('link', { name: 'Volver a la ficha de la persona' }).click()
    await expect(page).toHaveURL(new RegExp(`/employees/${EMPLOYEE_UUID}$`))
  },
)

test(
  'la ruta del registro horario se puede abrir directamente, con la sesion guardada',
  { tag: ['@RF-PA-03'] },
  async ({ page }) => {
    await stubManagementApi(page)
    await logIn(page)

    // Recarga en frio sobre la URL profunda: el panel recupera la sesion de
    // `sessionStorage` (`/auth/me`) y no manda al acceso.
    await page.goto(`/employees/${EMPLOYEE_UUID}/workdays`)

    await expect(page).toHaveURL(new RegExp(`/employees/${EMPLOYEE_UUID}/workdays$`))
    await expect(page.getByRole('heading', { level: 1, name: 'Registro horario' })).toBeVisible()
    await expect(page.getByTestId('workday')).toHaveCount(1)
  },
)
