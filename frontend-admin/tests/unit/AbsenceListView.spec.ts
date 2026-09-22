// Listado de ausencias (RF-GP-04, RF-ID-03).
//
// Lo que se comprueba: la tabla pinta el listado con sus filtros, y **la nota
// no se pinta si no viene** (decision 5 de la ficha): el servidor omite el
// campo entero para quien no tiene `employees:*`, y eso no es lo mismo que
// «nota vacía» (`null`). La columna se decide mirando los datos que llegan,
// nunca el rol de quien mira.
import { beforeEach, describe, expect, it } from 'vitest'
import AbsenceListView from '@/features/absences/AbsenceListView.vue'
import { useSessionStore } from '@/features/auth/session.store'
import type { Absence, AbsenceCollection } from '@/shared/api/types'
import es from '@/shared/i18n/locales/es.json'
import { managementUser } from './support/fixtures'
import { createTestPinia, jsonResponse, mountView, settle, stubRoutes } from './support/harness'

const DEPARTMENTS = { data: [{ id: 3, name: 'Recepción' }] }

const BASE_ABSENCE: Absence = {
  uuid: '0199f4a1-6c22-7e10-9b40-2a3b4c5d6e70',
  employee_uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
  employee_code: 'E7QK2MXPR',
  employee_name: 'Youssef Amrani',
  department_id: 3,
  department_name: 'Recepción',
  type: 'sick_leave',
  starts_on: '2026-03-10',
  ends_on: '2026-03-12',
  days: 3,
  status: 'active',
  version: 1,
  supersedes_uuid: null,
  superseded_by_uuid: null,
  change_reason: null,
  voided_at: null,
  void_reason: null,
  created_at: '2026-03-09T09:00:00.000000Z',
}

function absenceCollection(data: Absence[]): AbsenceCollection {
  return { data, meta: { page: 1, per_page: 30, total: data.length, total_pages: 1 } }
}

function signIn(abilities: readonly string[]): ReturnType<typeof createTestPinia> {
  const pinia = createTestPinia()
  const session = useSessionStore()

  session.user = managementUser({ abilities: [...abilities] })
  session.token = 'un-token'
  session.status = 'authenticated'

  return pinia
}

beforeEach(() => {
  window.sessionStorage.clear()
})

describe('AbsenceListView', () => {
  it('con nota, la pinta en su columna', async () => {
    const pinia = signIn(['employees:read', 'employees:*'])

    stubRoutes({
      '/departments': () => jsonResponse(DEPARTMENTS),
      '/absences': () =>
        jsonResponse(absenceCollection([{ ...BASE_ABSENCE, note: 'Baja por gripe.' }])),
    })

    const wrapper = await mountView(AbsenceListView, { pinia })

    await settle()

    expect(wrapper.text()).toContain(es.absences.table.note)
    expect(wrapper.text()).toContain('Baja por gripe.')
  })

  it('sin nota en la respuesta, no pinta ninguna columna de nota', async () => {
    // `responsable_departamento`: lleva `employees:read` y no `employees:*`
    // (decision 5), y el servidor omite `note` del todo -no lo manda a
    // `null`-. Esta prueba lo simula tal cual llega: sin la propiedad.
    const pinia = signIn(['employees:read'])

    const rowWithoutNote: Omit<Absence, 'note'> = { ...BASE_ABSENCE }

    stubRoutes({
      '/departments': () => jsonResponse(DEPARTMENTS),
      '/absences': () => jsonResponse(absenceCollection([rowWithoutNote as Absence])),
    })

    const wrapper = await mountView(AbsenceListView, { pinia })

    await settle()

    expect(wrapper.text()).not.toContain(es.absences.table.note)
    expect(
      wrapper.find('[data-test="absence-correct-0199f4a1-6c22-7e10-9b40-2a3b4c5d6e70"]').exists(),
    ).toBe(false)
    expect(
      wrapper.find('[data-test="absence-void-0199f4a1-6c22-7e10-9b40-2a3b4c5d6e70"]').exists(),
    ).toBe(false)
  })

  it('con permiso de escritura, ofrece registrar, importar, corregir y anular', async () => {
    const pinia = signIn(['employees:read', 'employees:*'])

    stubRoutes({
      '/departments': () => jsonResponse(DEPARTMENTS),
      '/absences': () => jsonResponse(absenceCollection([BASE_ABSENCE])),
    })

    const wrapper = await mountView(AbsenceListView, { pinia })

    await settle()

    expect(wrapper.find('[data-test="absences-register"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="absences-import"]').exists()).toBe(true)
    expect(wrapper.find(`[data-test="absence-correct-${BASE_ABSENCE.uuid}"]`).exists()).toBe(true)
    expect(wrapper.find(`[data-test="absence-void-${BASE_ABSENCE.uuid}"]`).exists()).toBe(true)
  })
})
