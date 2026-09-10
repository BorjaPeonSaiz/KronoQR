// Añadir, corregir o anular un tramo (RF-PA-04, RN-13, ADR-026, ADR-035).
//
// Lo que se comprueba: los tres modos piden lo que el contrato exige y nada
// mas, `OTROS` bloquea con menos de 20 caracteres, «qué se va a cambiar, desde
// que valor y hacia cual» aparece antes de confirmar (regla dura 5), las tres
// causas de un 409 se distinguen y ninguna pierde lo escrito, el 422 de
// cambio de jornada se pinta con la pista propia y no con el texto crudo del
// servidor, y las horas se convierten con la zona del centro (regla dura 3),
// nunca con la del navegador de la prueba.
import { describe, expect, it } from 'vitest'
import CorrectionDialog from '@/features/workdays/CorrectionDialog.vue'
import es from '@/shared/i18n/locales/es.json'
import type { CorrectedShiftEntry } from '@/shared/api/types'
import { EMPLOYEE_UUID, shiftEntry } from './support/fixtures'
import { jsonResponse, mountView, problemResponse, settle, stubFetch } from './support/harness'

const CORRECTED: CorrectedShiftEntry = {
  employee_uuid: EMPLOYEE_UUID,
  work_date: '2026-03-14',
  action: 'created',
  shift_entry_uuid: '0199f2c1-0000-0000-0000-000000000000',
  superseded_shift_entry_uuid: null,
  version: 1,
  status: 'closed',
  clocked_in_at: '2026-03-14T05:00:00.000000Z',
  clocked_out_at: '2026-03-14T13:00:00.000000Z',
  daily_total_minutes: 480,
}

describe('CorrectionDialog, modo «add»', () => {
  it('pide jornada, entrada, salida (opcional) y motivo, y nada de un tramo previo', async () => {
    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'add',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: undefined,
        entry: undefined,
      },
    })

    expect(wrapper.find('[data-test="dialog-work-date"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="dialog-clock-in"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="dialog-clock-out"]').exists()).toBe(true)
    expect(wrapper.text()).toContain(es.corrections.actions.create)
  })

  it('no deja guardar sin motivo ni sin hora de entrada', async () => {
    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'add',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: undefined,
        entry: undefined,
      },
    })

    expect(wrapper.find('[data-test="dialog-submit"]').attributes('disabled')).toBeDefined()

    await wrapper.find('[data-test="dialog-work-date"]').setValue('2026-08-14')
    await wrapper.find('[data-test="dialog-clock-in"]').setValue('2026-08-14T06:00')
    await wrapper.find('[data-test="dialog-reason"]').setValue('OLVIDO_FICHAJE_ENTRADA')
    await settle(1)

    expect(wrapper.find('[data-test="dialog-submit"]').attributes('disabled')).toBeUndefined()
  })

  it('no deja guardar sin jornada aunque el resto este completo', async () => {
    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'add',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: undefined,
        entry: undefined,
      },
    })

    await wrapper.find('[data-test="dialog-clock-in"]').setValue('2026-08-14T06:00')
    await wrapper.find('[data-test="dialog-reason"]').setValue('OLVIDO_FICHAJE_ENTRADA')
    await settle(1)

    expect(wrapper.find('[data-test="dialog-submit"]').attributes('disabled')).toBeDefined()

    await wrapper.find('[data-test="dialog-work-date"]').setValue('2026-08-14')
    await settle(1)

    expect(wrapper.find('[data-test="dialog-submit"]').attributes('disabled')).toBeUndefined()
  })

  it('una hora que no existio por el cambio de horario bloquea el envio y lo dice', async () => {
    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'add',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: undefined,
        entry: undefined,
      },
    })

    await wrapper.find('[data-test="dialog-work-date"]').setValue('2026-03-29')
    // 02:30 no existio nunca en Madrid ese dia: el reloj salta de 02:00 a 03:00.
    await wrapper.find('[data-test="dialog-clock-in"]').setValue('2026-03-29T02:30')
    await wrapper.find('[data-test="dialog-reason"]').setValue('OLVIDO_FICHAJE_ENTRADA')
    await settle(1)

    expect(wrapper.find('[data-test="dialog-submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain(es.corrections.dialog.timeDoesNotExist)
  })

  it('con el motivo «otros», bloquea con menos de 20 caracteres', async () => {
    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'add',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: undefined,
        entry: undefined,
      },
    })

    await wrapper.find('[data-test="dialog-work-date"]').setValue('2026-08-14')
    await wrapper.find('[data-test="dialog-clock-in"]').setValue('2026-08-14T06:00')
    await wrapper.find('[data-test="dialog-reason"]').setValue('OTROS')
    await settle(1)

    expect(wrapper.find('[data-test="dialog-reason-text"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="dialog-submit"]').attributes('disabled')).toBeDefined()

    await wrapper.find('[data-test="dialog-reason-text"]').setValue('demasiado corto')
    await settle(1)
    expect(wrapper.find('[data-test="dialog-submit"]').attributes('disabled')).toBeDefined()

    await wrapper
      .find('[data-test="dialog-reason-text"]')
      .setValue('Cambio de turno pactado con la compañera de tarde.')
    await settle(1)
    expect(wrapper.find('[data-test="dialog-submit"]').attributes('disabled')).toBeUndefined()
  })

  it('convierte la hora tecleada (zona del centro) a UTC al guardar', async () => {
    const spy = stubFetch(() => jsonResponse(CORRECTED, 201))

    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'add',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: undefined,
        entry: undefined,
      },
    })

    await wrapper.find('[data-test="dialog-work-date"]').setValue('2026-08-14')
    // 06:00 en Madrid en agosto (CEST, +02:00) es 04:00 UTC.
    await wrapper.find('[data-test="dialog-clock-in"]').setValue('2026-08-14T06:00')
    await wrapper.find('[data-test="dialog-reason"]').setValue('OLVIDO_FICHAJE_ENTRADA')
    await settle(1)
    await wrapper.find('#correction-form').trigger('submit')
    await settle()

    const body = spy.mock.calls
      .map((call) => call[1] as RequestInit | undefined)
      .map((init) => String(init?.body ?? ''))
      .find((raw) => raw.includes('employee_uuid'))

    expect(body).toContain('2026-08-14T04:00:00.000Z')
    expect(wrapper.emitted('success')).toBeDefined()
  })
})

describe('CorrectionDialog, modo «correct»', () => {
  it('rellena las horas con lo que ya trae el tramo, sin convertir nada', async () => {
    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'correct',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: '2026-03-14',
        entry: shiftEntry(),
      },
    })

    const clockIn = wrapper.find('[data-test="dialog-clock-in"]').element as HTMLInputElement
    const clockOut = wrapper.find('[data-test="dialog-clock-out"]').element as HTMLInputElement

    expect(clockIn.value).toBe('2026-03-14T06:00')
    expect(clockOut.value).toBe('2026-03-14T14:05')
  })

  it('no deja guardar si no se cambia ninguna hora', async () => {
    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'correct',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: '2026-03-14',
        entry: shiftEntry(),
      },
    })

    await wrapper.find('[data-test="dialog-reason"]').setValue('AJUSTE_ACORDADO_CON_RRHH')
    await settle(1)

    expect(wrapper.find('[data-test="dialog-no-change"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="dialog-submit"]').attributes('disabled')).toBeDefined()
  })

  it('enseña que va a cambiar, desde que hora y hacia cual, antes de guardar', async () => {
    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'correct',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: '2026-03-14',
        entry: shiftEntry(),
      },
    })

    await wrapper.find('[data-test="dialog-clock-out"]').setValue('2026-03-14T14:30')
    await settle(1)

    const preview = wrapper.find('[data-test="dialog-preview"]')

    expect(preview.text()).toContain('14:05')
    expect(preview.text()).toContain('14:30')
    // La hora de entrada no cambio: no aparece como fila del cambio.
    expect(preview.text()).not.toContain(es.workdays.history.fields.in)
  })

  it('solo manda el campo que de verdad cambio (un campo ausente es «no lo toques»)', async () => {
    const spy = stubFetch(() => jsonResponse({ ...CORRECTED, action: 'modified' }))

    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'correct',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: '2026-03-14',
        entry: shiftEntry(),
      },
    })

    await wrapper.find('[data-test="dialog-clock-out"]').setValue('2026-03-14T14:30')
    await wrapper.find('[data-test="dialog-reason"]').setValue('AJUSTE_ACORDADO_CON_RRHH')
    await settle(1)
    await wrapper.find('#correction-form').trigger('submit')
    await settle()

    const body = spy.mock.calls
      .map((call) => call[1] as RequestInit | undefined)
      .map((init) => String(init?.body ?? ''))
      .find((raw) => raw.includes('reason_code'))

    expect(body).not.toContain('clocked_in_at')
    expect(body).toContain('clocked_out_at')
  })

  it('cambiar el motivo desde «otros» a otro vacia el texto libre: no viaja una justificacion de otra cosa', async () => {
    const spy = stubFetch(() => jsonResponse({ ...CORRECTED, action: 'modified' }))

    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'correct',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: '2026-03-14',
        entry: shiftEntry(),
      },
    })

    await wrapper.find('[data-test="dialog-clock-out"]').setValue('2026-03-14T14:30')
    await wrapper.find('[data-test="dialog-reason"]').setValue('OTROS')
    await wrapper
      .find('[data-test="dialog-reason-text"]')
      .setValue('Texto que ya no deberia viajar tras cambiar de motivo.')
    await settle(1)

    // Al cambiar a un motivo distinto de OTROS, el campo de texto libre
    // desaparece del formulario (hallazgo 6b) y no vuelve a formar parte del
    // envio.
    await wrapper.find('[data-test="dialog-reason"]').setValue('AJUSTE_ACORDADO_CON_RRHH')
    await settle(1)

    expect(wrapper.find('[data-test="dialog-reason-text"]').exists()).toBe(false)

    await wrapper.find('#correction-form').trigger('submit')
    await settle()

    const body = spy.mock.calls
      .map((call) => call[1] as RequestInit | undefined)
      .map((init) => String(init?.body ?? ''))
      .find((raw) => raw.includes('reason_code'))

    expect(body).toContain('"reason_text":null')
    expect(body).not.toContain('ya no deberia viajar')
  })

  it('un 409 de version superada avisa, pide recargar y no pierde lo escrito', async () => {
    stubFetch(() => problemResponse(409, 'urn:kronoqr:problem:shift-entry-superseded'))

    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'correct',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: '2026-03-14',
        entry: shiftEntry(),
      },
    })

    await wrapper.find('[data-test="dialog-clock-out"]').setValue('2026-03-14T14:30')
    await wrapper.find('[data-test="dialog-reason"]').setValue('AJUSTE_ACORDADO_CON_RRHH')
    await settle(1)
    await wrapper.find('#correction-form').trigger('submit')
    await settle()

    expect(wrapper.find('[data-test="dialog-conflict"]').text()).toContain(
      es.corrections.supersededNotice,
    )
    // El formulario NUNCA se desmonta (hallazgo 2): el valor tecleado sigue
    // en el campo, no un hueco vacio.
    const clockOut = wrapper.find('[data-test="dialog-clock-out"]').element as HTMLInputElement

    expect(clockOut.value).toBe('2026-03-14T14:30')
    expect(wrapper.emitted('stale')).toBeDefined()
    expect(wrapper.emitted('success')).toBeUndefined()
    // Sin boton de guardar: reintentar sobre esa version ya no tiene sentido.
    expect(wrapper.find('[data-test="dialog-submit"]').exists()).toBe(false)
  })

  it('un 409 de turno ya abierto avisa sin recargar y deja corregir de nuevo', async () => {
    stubFetch(() => problemResponse(409, 'urn:kronoqr:problem:shift-already-open'))

    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'correct',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: '2026-03-14',
        entry: shiftEntry(),
      },
    })

    await wrapper.find('[data-test="dialog-clock-out"]').setValue('2026-03-14T14:30')
    await wrapper.find('[data-test="dialog-reason"]').setValue('AJUSTE_ACORDADO_CON_RRHH')
    await settle(1)
    await wrapper.find('#correction-form').trigger('submit')
    await settle()

    expect(wrapper.find('[data-test="dialog-conflict"]').text()).toContain(
      es.corrections.conflict.shiftAlreadyOpen,
    )
    expect(wrapper.emitted('stale')).toBeUndefined()
    const clockOut = wrapper.find('[data-test="dialog-clock-out"]').element as HTMLInputElement

    expect(clockOut.value).toBe('2026-03-14T14:30')
    // Con boton de guardar: se puede corregir y reintentar sin recargar.
    expect(wrapper.find('[data-test="dialog-submit"]').exists()).toBe(true)
  })

  it('un 409 de solape avisa sin recargar y deja corregir de nuevo', async () => {
    stubFetch(() => problemResponse(409, 'urn:kronoqr:problem:overlapping-shift-entry'))

    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'correct',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: '2026-03-14',
        entry: shiftEntry(),
      },
    })

    await wrapper.find('[data-test="dialog-clock-out"]').setValue('2026-03-14T14:30')
    await wrapper.find('[data-test="dialog-reason"]').setValue('AJUSTE_ACORDADO_CON_RRHH')
    await settle(1)
    await wrapper.find('#correction-form').trigger('submit')
    await settle()

    expect(wrapper.find('[data-test="dialog-conflict"]').text()).toContain(
      es.corrections.conflict.overlap,
    )
    expect(wrapper.find('[data-test="dialog-submit"]').exists()).toBe(true)
  })

  it('un 409 sin tipo reconocido no afirma que sea la version superada', async () => {
    stubFetch(() => problemResponse(409, 'urn:kronoqr:problem:conflict'))

    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'correct',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: '2026-03-14',
        entry: shiftEntry(),
      },
    })

    await wrapper.find('[data-test="dialog-clock-out"]').setValue('2026-03-14T14:30')
    await wrapper.find('[data-test="dialog-reason"]').setValue('AJUSTE_ACORDADO_CON_RRHH')
    await settle(1)
    await wrapper.find('#correction-form').trigger('submit')
    await settle()

    const notice = wrapper.find('[data-test="dialog-conflict"]').text()

    expect(notice).toContain(es.corrections.conflict.generic)
    expect(notice).not.toContain(es.corrections.supersededNotice)
    expect(wrapper.emitted('stale')).toBeUndefined()
  })

  it('un 422 de cambio de jornada se pinta como pista del campo, no como el texto crudo del servidor', async () => {
    stubFetch(() =>
      problemResponse(422, 'urn:kronoqr:problem:correction-would-change-work-date', {
        errors: { clocked_in_at: ['texto crudo del servidor que no debe verse'] },
      }),
    )

    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'correct',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: '2026-03-14',
        entry: shiftEntry(),
      },
    })

    await wrapper.find('[data-test="dialog-clock-in"]').setValue('2026-03-15T00:30')
    await wrapper.find('[data-test="dialog-reason"]').setValue('AJUSTE_ACORDADO_CON_RRHH')
    await settle(1)
    await wrapper.find('#correction-form').trigger('submit')
    await settle()

    const text = wrapper.text()

    expect(text).toContain(es.corrections.wouldChangeWorkDate)
    expect(text).not.toContain('texto crudo del servidor')
    const clockIn = wrapper.find('[data-test="dialog-clock-in"]').element as HTMLInputElement

    expect(clockIn.value).toBe('2026-03-15T00:30')
  })

  it('un 422 generico con el mismo campo, pero sin el `type` de cambio de jornada, NO se confunde con el', async () => {
    // Contraprueba de la anterior: el reconocimiento es por `type`, no por
    // campo (correccion de revision, M1 del cierre de la Fase 5). Antes de
    // esa correccion, cualquier `422` sobre `clocked_in_at` en modo «correct»
    // con la entrada cambiada se pintaba como cambio de jornada aunque fuera
    // otra cosa.
    stubFetch(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: { clocked_in_at: ['texto crudo del servidor que no debe verse'] },
      }),
    )

    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'correct',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: '2026-03-14',
        entry: shiftEntry(),
      },
    })

    await wrapper.find('[data-test="dialog-clock-in"]').setValue('2026-03-15T00:30')
    await wrapper.find('[data-test="dialog-reason"]').setValue('AJUSTE_ACORDADO_CON_RRHH')
    await settle(1)
    await wrapper.find('#correction-form').trigger('submit')
    await settle()

    expect(wrapper.text()).not.toContain(es.corrections.wouldChangeWorkDate)
  })

  it('vaciar la salida de un tramo cerrado no cuenta como cambio y avisa de que no se puede retirar', async () => {
    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'correct',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: '2026-03-14',
        entry: shiftEntry(),
      },
    })

    await wrapper.find('[data-test="dialog-clock-out"]').setValue('')
    await wrapper.find('[data-test="dialog-reason"]').setValue('AJUSTE_ACORDADO_CON_RRHH')
    await settle(1)

    // No es un cambio: sin otra hora tocada, sigue sin haber nada que guardar.
    expect(wrapper.find('[data-test="dialog-no-change"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="dialog-submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain(es.corrections.dialog.clockOutCannotBeCleared)
    // Y la previsualizacion no dice "→ Sin cerrar": no hay fila de cambio.
    expect(wrapper.find('[data-test="dialog-preview"]').exists()).toBe(false)
  })
})

describe('CorrectionDialog, modo «void»', () => {
  it('no pide ninguna hora: solo el motivo, y avisa de que el tramo deja de contar', async () => {
    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'void',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: '2026-03-14',
        entry: shiftEntry(),
      },
    })

    expect(wrapper.find('[data-test="dialog-clock-in"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="dialog-clock-out"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="dialog-void-summary"]').text()).toBe(
      es.corrections.dialog.voidSummary,
    )
  })

  it('enseña las horas del tramo como «antes» y «anulado» como «despues»', async () => {
    const wrapper = await mountView(CorrectionDialog, {
      props: {
        mode: 'void',
        employeeUuid: EMPLOYEE_UUID,
        employeeName: 'Youssef Amrani',
        timeZone: 'Europe/Madrid',
        workDate: '2026-03-14',
        entry: shiftEntry(),
      },
    })

    const preview = wrapper.find('[data-test="dialog-preview"]')

    expect(preview.text()).toContain('14:05')
    expect(preview.text()).toContain(es.workdays.history.noEntryAfter)
  })
})
