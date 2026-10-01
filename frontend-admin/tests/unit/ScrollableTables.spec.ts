// Tablas con desplazamiento (AX7-01, AX7-02, PA7-003 · WCAG 2.1.1, 1.3.1).
//
// Un contenedor con scroll que no contiene nada enfocable no se puede desplazar
// con el teclado: axe lo da como `scrollable-region-focusable`. La solucion es la
// del portal (`ShiftEntryTable`): `tabindex="0"`, `role="region"` y nombre.
// Ademas, las dos tablas virtualizadas pintan solo una ventana de filas, asi que
// declaran el total y la posicion de cada fila (`aria-rowcount`/`aria-rowindex`).
import { afterEach, describe, expect, it } from 'vitest'
import IncidentTable from '@/features/incidents/IncidentTable.vue'
import PresenceTable from '@/features/live/PresenceTable.vue'
import type { LivePresenceEntry } from '@/shared/api/types'
import { incident } from './support/fixtures'
import { mountView, settle } from './support/harness'

afterEach(() => {
  document.body.innerHTML = ''
})

function presenceEntry(index: number): LivePresenceEntry {
  return {
    employee_uuid: `0199f0c2-1f4a-7c3e-9b21-${String(index).padStart(12, '0')}`,
    full_name: `Persona ${index}`,
    department: { id: 3, name: 'Cocina' },
    status: 'present',
    shift_entry_uuid: `0199f2c1-8a10-7b40-9c50-${String(index).padStart(12, '0')}`,
    clocked_in_at: '2026-03-14T05:00:00.000000Z',
    origin: 'qr_kiosk',
    device: null,
  }
}

describe('PresenceTable: region desplazable y filas indexadas (AX7-02, PA7-003)', () => {
  it('el contenedor con scroll es una region enfocable y con nombre', async () => {
    const wrapper = await mountView(PresenceTable, {
      props: {
        entries: [presenceEntry(1), presenceEntry(2)],
        timeZone: 'Europe/Madrid',
        serverNowMs: Date.parse('2026-03-14T09:00:00Z'),
      },
    })
    const scroller = wrapper.find('[data-test="presence-table"]')

    expect(scroller.attributes('tabindex')).toBe('0')
    expect(scroller.attributes('role')).toBe('region')
    expect(scroller.attributes('aria-label')).toContain('CET')
  })

  it('declara el total de filas con la cabecera y la posicion de cada una', async () => {
    const wrapper = await mountView(PresenceTable, {
      props: {
        entries: [presenceEntry(1), presenceEntry(2), presenceEntry(3)],
        timeZone: 'Europe/Madrid',
        serverNowMs: Date.parse('2026-03-14T09:00:00Z'),
      },
    })

    expect(wrapper.find('[role="table"]').attributes('aria-rowcount')).toBe('4')
    expect(
      wrapper.find('[role="columnheader"]').element.parentElement?.getAttribute('aria-rowindex'),
    ).toBe('1')
    expect(
      wrapper.findAll('[data-test="presence-entry"]').map((row) => row.attributes('aria-rowindex')),
    ).toEqual(['2', '3', '4'])
  })

  it('con una plantilla virtualizada el total sigue siendo el de todas las filas', async () => {
    const entries = Array.from({ length: 500 }, (_, index) => presenceEntry(index + 1))
    const wrapper = await mountView(PresenceTable, {
      props: {
        entries,
        timeZone: 'Europe/Madrid',
        serverNowMs: Date.parse('2026-03-14T09:00:00Z'),
      },
    })

    await settle()

    // 500 personas y la cabecera: el lector sabe que hay mas de las que se pintan.
    expect(wrapper.find('[role="table"]').attributes('aria-rowcount')).toBe('501')
  })
})

describe('IncidentTable: region desplazable y filas indexadas (AX7-02, PA7-003)', () => {
  it('el contenedor con scroll es una region enfocable y con nombre', async () => {
    const wrapper = await mountView(IncidentTable, {
      props: {
        entries: [incident()],
        timeZone: 'Europe/Madrid',
        serverNowMs: Date.parse('2026-03-14T09:00:00Z'),
      },
    })
    const scroller = wrapper.find('[data-test="incident-table"]')

    expect(scroller.attributes('tabindex')).toBe('0')
    expect(scroller.attributes('role')).toBe('region')
    expect(scroller.attributes('aria-label')).not.toBe('')
  })

  it('declara el total de filas con la cabecera y la posicion de cada una', async () => {
    const wrapper = await mountView(IncidentTable, {
      props: {
        entries: [incident({ id: 1 }), incident({ id: 2 })],
        timeZone: 'Europe/Madrid',
        serverNowMs: Date.parse('2026-03-14T09:00:00Z'),
      },
    })

    expect(wrapper.find('[role="table"]').attributes('aria-rowcount')).toBe('3')
    expect(
      wrapper.findAll('[data-test="incident-row"]').map((row) => row.attributes('aria-rowindex')),
    ).toEqual(['2', '3'])
  })
})
