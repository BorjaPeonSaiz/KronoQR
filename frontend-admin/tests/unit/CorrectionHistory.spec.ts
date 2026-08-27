// Historico de correcciones de una jornada (RF-PA-03, RN-13, RL-04).
//
// La regla dura 5 dice que nada se borra. Esta prueba comprueba lo que eso
// significa en una pantalla: que se vea QUE cambio, DESDE que valor y HACIA
// cual, quien lo firmo, cuando y con que motivo — y que un tramo anulado o un
// alta no se conviertan en un hueco.
import { describe, expect, it } from 'vitest'
import CorrectionHistory from '@/features/workdays/CorrectionHistory.vue'
import es from '@/shared/i18n/locales/es.json'
import type { WorkDayCorrection } from '@/shared/api/types'
import { correction } from './support/fixtures'
import { mountView } from './support/harness'

function mountHistory(corrections: WorkDayCorrection[]): ReturnType<typeof mountView> {
  return mountView(CorrectionHistory, {
    props: { corrections, timeZone: 'Europe/Madrid' },
  })
}

describe('CorrectionHistory', () => {
  it('enseña el «de → a» de la correccion, no solo el valor de ahora', async () => {
    const wrapper = await mountHistory([correction()])
    const table = wrapper.find('table')

    expect(table.text()).toContain(es.workdays.history.before)
    expect(table.text()).toContain(es.workdays.history.after)
    // El turno estaba sin cerrar y paso a cerrarse a las 14:05 del centro.
    expect(table.text()).toContain(es.workdays.history.openMark)
    expect(table.text()).toContain('14:05')
    // Y lo que decia antes en minutos, junto a lo que dice ahora.
    expect(table.text()).toContain('0 h 00 min')
    expect(table.text()).toContain('8 h 05 min')
  })

  it('omite las filas que no cambiaron, para que se vea la que si', async () => {
    const wrapper = await mountHistory([correction()])
    const rows = wrapper.findAll('tbody th')

    // La hora de entrada no la toco nadie: no aparece como cambio.
    expect(rows.map((row) => row.text())).not.toContain(es.workdays.history.fields.in)
    expect(rows.map((row) => row.text())).toContain(es.workdays.history.fields.out)
  })

  it('dice quien firmo la correccion, cuando y con que motivo del catalogo', async () => {
    const wrapper = await mountHistory([correction()])
    const text = wrapper.text()

    expect(text).toContain('Cuenta de RRHH')
    expect(text).toContain(es.corrections.reasons.OLVIDO_FICHAJE_SALIDA)
    expect(text).toContain(es.corrections.action.closed)
    // El momento de la correccion, en la zona del centro: 16:22 del 14 de marzo.
    expect(text).toContain('16:22')
  })

  it('enseña el texto libre cuando el motivo es «otros»', async () => {
    const wrapper = await mountHistory([
      correction({
        reason_code: 'OTROS',
        reason_text: 'Cambio de turno pactado en el comite del 12 de marzo.',
      }),
    ])

    expect(wrapper.text()).toContain('comite del 12 de marzo')
  })

  it('en un alta dice que antes no existia el tramo, no un hueco', async () => {
    const wrapper = await mountHistory([
      correction({
        action: 'created',
        reason_code: 'ALTA_RETROACTIVA',
        before: null,
      }),
    ])

    expect(wrapper.text()).toContain(es.workdays.history.noEntryBefore)
    expect(wrapper.text()).toContain(es.corrections.action.created)
  })

  it('una anulacion sigue visible y dice que el tramo se anulo', async () => {
    const wrapper = await mountHistory([
      correction({
        action: 'voided',
        reason_code: 'ERROR_DE_ESCANEO_DUPLICADO',
        before: {
          version: 2,
          clocked_in_at: '2026-03-14T05:00:00.000000Z',
          clocked_out_at: '2026-03-14T13:05:00.000000Z',
          worked_minutes: 485,
        },
        after: null,
      }),
    ])

    expect(wrapper.text()).toContain(es.workdays.history.noEntryAfter)
    expect(wrapper.text()).toContain(es.corrections.action.voided)
    // Y lo que decia el tramo antes de anularse sigue ahi.
    expect(wrapper.text()).toContain('8 h 05 min')
  })

  it('conserva la cadena entera cuando una jornada se corrigio dos veces', async () => {
    const wrapper = await mountHistory([
      correction(),
      correction({
        action: 'modified',
        reason_code: 'AJUSTE_ACORDADO_CON_RRHH',
        performed_at: '2026-03-20T09:00:00.000000Z',
        performed_at_local: '2026-03-20T10:00:00.000000+01:00',
        performed_by: { uuid: '0199f0aa-2222-7000-8000-0123456789ab', name: 'Dirección' },
      }),
    ])

    expect(wrapper.findAll('[data-test="correction"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Cuenta de RRHH')
    expect(wrapper.text()).toContain('Dirección')
  })

  it('sin correcciones dice que la jornada no se ha tocado, no «sin datos»', async () => {
    const wrapper = await mountHistory([])

    expect(wrapper.find('[data-test="history-empty"]').text()).toBe(es.workdays.history.empty)
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('no llama «valor actual» a lo que se rectifico hace semanas', async () => {
    const wrapper = await mountHistory([correction()])

    expect(wrapper.find('table').text()).not.toContain(es.common.change.from)
  })
})
