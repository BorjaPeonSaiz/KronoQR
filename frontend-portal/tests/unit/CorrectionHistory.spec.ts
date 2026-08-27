// Historico de correcciones de una jornada propia (RL-05, RN-13, RL-04).
//
// La regla dura 5 dice que nada se borra. Esta prueba comprueba lo que eso
// significa en esta pantalla: que se vea el «antes» y el «despues» de cada
// corregido, quien la firmo, cuando y con que motivo — y que un tramo anulado
// o un alta no se conviertan en un hueco.
import { describe, expect, it } from 'vitest'
import CorrectionHistory from '@/features/my-records/CorrectionHistory.vue'
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
  it('enseña el antes y el despues de la correccion, no solo el valor de ahora', async () => {
    const wrapper = await mountHistory([correction()])
    const table = wrapper.find('table')

    expect(table.text()).toContain(es.myRecords.history.before)
    expect(table.text()).toContain(es.myRecords.history.after)
    // El turno estaba sin cerrar y paso a cerrarse a las 14:05 del centro.
    expect(table.text()).toContain(es.myRecords.history.openMark)
    expect(table.text()).toContain('14:05')
    // Y lo que decia antes en minutos, junto a lo que dice ahora.
    expect(table.text()).toContain('0 h 00 min')
    expect(table.text()).toContain('8 h 05 min')
  })

  it('dice quien firmo la correccion, cuando y con que motivo del catalogo', async () => {
    const wrapper = await mountHistory([correction()])
    const text = wrapper.text()

    expect(text).toContain('Cuenta de RRHH')
    expect(text).toContain(es.myRecords.reasons.OLVIDO_FICHAJE_SALIDA)
    expect(text).toContain(es.myRecords.correctionAction.closed)
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

    expect(wrapper.text()).toContain(es.myRecords.history.noEntryBefore)
    expect(wrapper.text()).toContain(es.myRecords.correctionAction.created)
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

    expect(wrapper.text()).toContain(es.myRecords.history.noEntryAfter)
    expect(wrapper.text()).toContain(es.myRecords.correctionAction.voided)
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

    expect(wrapper.find('[data-test="history-empty"]').text()).toBe(es.myRecords.history.empty)
    expect(wrapper.find('table').exists()).toBe(false)
  })

  // --- El bug de ADR-036: `performed_at_local`, no `performed_at` reconvertido ---
  //
  // Antes de compartir `datetime.ts` con el panel via `@kronoqr/web-kit`, esta
  // pantalla convertia `performed_at` (UTC) ella misma. Lo correcto es LEER
  // `performed_at_local`, que el servidor ya resolvio en la zona del centro
  // (regla dura 3): la conversion en el cliente es exactamente el calculo que
  // una noche de cambio de hora puede hacer discrepar entre panel y portal.
  //
  // La noche del sabado 24 al domingo 25 de octubre de 2026 es, en España, la
  // del cambio de hora de otono (el mismo escenario que siembra
  // `EdgeCaseSeeder` para los tramos, aunque no para correcciones): la 01:00
  // UTC hace que las 03:00 CEST retrocedan a las 02:00 CET, y por eso la hora
  // civil «02:30» ocurre DOS VECES esa noche, con un desplazamiento distinto
  // cada vez. Firmar dos correcciones exactamente en esa hora ambigua, una
  // antes del cambio y otra despues, es la prueba mas directa de que la
  // pantalla lee el instante ya resuelto en vez de recalcularlo: las dos deben
  // pintar «02:30», con su zona (CEST/CET) distinguiendolas, sin que la
  // pantalla tenga que decidir nada por si misma.
  it('en la noche de cambio de hora de octubre pinta la hora que resolvio el servidor, no la que recalcula', async () => {
    const beforeChange = correction({
      performed_at: '2026-10-25T00:30:00.000000Z',
      performed_at_local: '2026-10-25T02:30:00.000000+02:00',
      performed_by: { uuid: '0199f0aa-3333-7000-8000-0123456789ab', name: 'Cuenta de RRHH' },
    })
    const afterChange = correction({
      performed_at: '2026-10-25T01:30:00.000000Z',
      performed_at_local: '2026-10-25T02:30:00.000000+01:00',
      performed_by: { uuid: '0199f0aa-4444-7000-8000-0123456789ab', name: 'Cuenta de RRHH' },
    })

    const wrapper = await mountHistory([beforeChange, afterChange])
    const items = wrapper.findAll('[data-test="correction"]')

    expect(items).toHaveLength(2)
    // Las dos, la misma hora civil: 02:30. Ninguna se desplaza ni se redondea
    // porque la pantalla recalcule algo que el servidor ya habia resuelto.
    expect(items[0]?.text()).toContain('02:30')
    expect(items[1]?.text()).toContain('02:30')
    // Y siguen siendo instantes distintos, una hora real aparte: la etiqueta de
    // zona las distingue (CEST antes del cambio, CET despues).
    expect(items[0]?.text()).toContain('CEST')
    expect(items[1]?.text()).toContain('CET')
  })
})
