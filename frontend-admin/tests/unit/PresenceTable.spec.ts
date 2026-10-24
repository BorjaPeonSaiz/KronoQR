// @vitest-environment jsdom
// La hora de entrada de la presencia en vivo (RF-PA-01): el dia se compara en la
// zona del centro con el «ahora» del servidor, nunca con el reloj del navegador.
import { describe, expect, it } from 'vitest'
import PresenceTable from '@/features/live/PresenceTable.vue'
import es from '@/shared/i18n/locales/es.json'
import type { LivePresenceEntry } from '@/shared/api/types'
import { mountView, settle } from './support/harness'

function entry(clockedInAt: string): LivePresenceEntry {
  return {
    employee_uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
    full_name: 'Youssef Amrani',
    department: { id: 3, name: 'Cocina' },
    status: 'present',
    shift_entry_uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
    clocked_in_at: clockedInAt,
    origin: 'qr_kiosk',
    device: { uuid: '0199f0d3-3c71-7e52-9a13-6f7a8b9c0d12', name: 'Entrada de personal' },
  }
}

async function since(clockedInAt: string, serverNow: string): Promise<string> {
  const wrapper = await mountView(PresenceTable, {
    props: {
      entries: [entry(clockedInAt)],
      timeZone: 'Europe/Madrid',
      serverNowMs: new Date(serverNow).getTime(),
    },
  })
  await settle()

  const text = wrapper.find('[data-test="entry-since"]').text()
  wrapper.unmount()

  return text
}

describe('PresenceTable: hora de entrada', () => {
  it('un turno de noche que entro ayer en el centro lo dice (RN-05)', async () => {
    // 21:00 UTC del 14 son las 22:00 del 14 en Madrid; a las 01:00 UTC del 15 ya es el 15.
    expect(await since('2026-03-14T21:00:00Z', '2026-03-15T01:00:00Z')).toBe(
      es.live.table.previousDay.replace('{time}', '22:00'),
    )
  })

  it('el dia es el del centro, no el UTC: 23:30 UTC ya es manana en Madrid', async () => {
    // Entrada a las 23:30 UTC del 14 = 00:30 del 15 en Madrid; ahora, 02:00 del 15 en Madrid.
    expect(await since('2026-03-14T23:30:00Z', '2026-03-15T01:00:00Z')).toBe('00:30')
  })

  it('justo antes de la medianoche del centro sigue siendo el mismo dia', async () => {
    expect(await since('2026-03-14T22:00:00Z', '2026-03-14T22:59:59Z')).toBe('23:00')
  })
})
