// Corregir una ausencia (RF-GP-04, RN-13, doc 03 §4.3).
//
// Lo que se comprueba: el dialogo enseña «que cambia, desde que valor y hacia
// cual» ANTES de confirmar (regla dura 5) y solo con las filas que de verdad
// cambian, el motivo es obligatorio entre 3 y 500 caracteres, y sin ningun
// cambio no se puede enviar (una correccion que no cambia nada es una fila
// que miente).
import { describe, expect, it } from 'vitest'
import AbsenceCorrectDialog from '@/features/absences/AbsenceCorrectDialog.vue'
import type { Absence } from '@/shared/api/types'
import es from '@/shared/i18n/locales/es.json'
import { mountView } from './support/harness'

const ABSENCE: Absence = {
  uuid: '0199f4a1-6c22-7e10-9b40-2a3b4c5d6e70',
  employee_uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
  employee_code: 'E7QK2MXPR',
  employee_name: 'Youssef Amrani',
  department_id: 3,
  department_name: 'Recepción',
  type: 'vacation',
  starts_on: '2026-03-02',
  ends_on: '2026-03-06',
  days: 5,
  note: 'Vacaciones de primavera.',
  status: 'active',
  version: 1,
  supersedes_uuid: null,
  superseded_by_uuid: null,
  change_reason: null,
  voided_at: null,
  void_reason: null,
  created_at: '2026-02-20T09:14:02.118000Z',
}

describe('AbsenceCorrectDialog', () => {
  it('no deja confirmar sin ningun cambio, aunque el motivo sea valido', async () => {
    const wrapper = await mountView(AbsenceCorrectDialog, { props: { absence: ABSENCE } })

    await wrapper.find('[data-test="correct-reason"]').setValue('El parte se prorrogó una semana.')

    expect(wrapper.find('[data-test="dialog-submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="dialog-no-change"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="dialog-preview"]').exists()).toBe(false)
  })

  it('enseña que cambia, desde que valor y hacia cual, antes de confirmar', async () => {
    const wrapper = await mountView(AbsenceCorrectDialog, { props: { absence: ABSENCE } })

    await wrapper.find('[data-test="correct-ends-on"]').setValue('2026-03-13')

    const preview = wrapper.find('[data-test="dialog-preview"]')

    expect(preview.exists()).toBe(true)
    // La fila lleva el campo, el valor de partida y el nuevo: los tres, no
    // solo el nombre del campo (asi se lee «Hasta: de 6 mar 2026 a 13 mar
    // 2026» y no una frase que esconde el valor de partida).
    expect(preview.text()).toContain(es.absences.fields.endsOn)
    expect(preview.text()).toContain('6 mar 2026')
    expect(preview.text()).toContain('13 mar 2026')

    // Sin motivo todavia, el envio sigue bloqueado.
    expect(wrapper.find('[data-test="dialog-submit"]').attributes('disabled')).toBeDefined()

    await wrapper.find('[data-test="correct-reason"]').setValue('El parte se prorrogó una semana.')

    expect(wrapper.find('[data-test="dialog-submit"]').attributes('disabled')).toBeUndefined()
  })

  it('un motivo de menos de 3 caracteres no basta para confirmar', async () => {
    const wrapper = await mountView(AbsenceCorrectDialog, { props: { absence: ABSENCE } })

    await wrapper.find('[data-test="correct-ends-on"]').setValue('2026-03-13')
    await wrapper.find('[data-test="correct-reason"]').setValue('Ok')

    expect(wrapper.find('[data-test="dialog-submit"]').attributes('disabled')).toBeDefined()
  })
})
