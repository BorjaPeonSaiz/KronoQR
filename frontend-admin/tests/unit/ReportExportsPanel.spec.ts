// Bloque «Exportaciones en segundo plano» (RF-IN-06, RF-IN-07, tarea 3.9).
//
// Lo que puede salir mal el día que alguien lo usa:
//
//  - Que el panel siga sondeando el servidor cuando ya no hay nada pendiente
//    ni en curso, o que siga haciéndolo tras desmontarse la pantalla.
//  - Que «Descargar» reutilice un enlace ya visto en vez de pedir uno nuevo
//    cada vez (decisión 3 de la ficha, ADR-041): el enlace es de un solo uso.
//  - Que un fallo al descargar deje el botón bloqueado para siempre.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ReportExportsPanel from '@/features/reports/ReportExportsPanel.vue'
import es from '@/shared/i18n/locales/es.json'
import type { ReportExport } from '@/shared/api/types'
import { clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { SITE } from './support/fixtures'
import { jsonResponse, mountView, problemResponse, settle, stubRoutes } from './support/harness'

const UUID = '0199f7b1-0000-7c3d-9e4f-5a6b7c8d9e02'

function reportExport(overrides: Partial<ReportExport> = {}): ReportExport {
  return {
    uuid: UUID,
    kind: 'period',
    format: 'csv',
    status: 'completed',
    parameters: {
      from: '2026-01-01',
      to: '2026-06-30',
      granularity: 'month',
      group_by: 'employee',
      include_open_shifts: false,
      department_id: null,
      employee_uuid: null,
    },
    scope: 'all',
    requested_by: { uuid: '0199f0aa-1111-7000-8000-0123456789ab', name: 'Dirección RRHH' },
    requested_at: '2026-07-01T08:00:00.000000Z',
    started_at: '2026-07-01T08:00:02.000000Z',
    completed_at: '2026-07-01T08:00:40.000000Z',
    failed_at: null,
    failure_reason: null,
    file_name: 'kronoqr-horas-2026-01-01_2026-06-30.csv',
    size_bytes: 1_048_576,
    sha256: '0'.repeat(64),
    row_count: 620,
    criteria: ['Los totales salen del registro horario ya consolidado.'],
    expires_at: '2026-07-08T08:00:40.000000Z',
    purged_at: null,
    downloaded_at: null,
    download_count: 0,
    notified_at: '2026-07-01T08:00:40.000000Z',
    notification_channel: 'panel',
    download: null,
    ...overrides,
  }
}

async function mountPanelRaw(): Promise<Awaited<ReturnType<typeof mountView>>> {
  return mountView(ReportExportsPanel)
}

async function mountPanel(): Promise<Awaited<ReturnType<typeof mountView>>> {
  const wrapper = await mountPanelRaw()

  await settle()

  return wrapper
}

beforeEach(() => {
  clearAnnouncement()
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.useRealTimers()
})

describe('ReportExportsPanel', () => {
  it('el vacío explica que todavía no hay ninguna exportación', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/reports/exports': () => jsonResponse({ data: [] }),
    })

    const wrapper = await mountPanel()

    expect(wrapper.text()).toContain(es.reportExports.list.empty.title)
  })

  it('enseña el tipo, el formato, el periodo y permite descargar solo si está completada', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/reports/exports': () =>
        jsonResponse({
          data: [
            reportExport({ uuid: `${UUID}-1`, status: 'completed', kind: 'payroll' }),
            reportExport({
              uuid: `${UUID}-2`,
              status: 'running',
              file_name: null,
              size_bytes: null,
              row_count: null,
              expires_at: null,
            }),
          ],
        }),
    })

    const wrapper = await mountPanel()

    expect(wrapper.find(`[data-test="status-${UUID}-1"]`).text()).toBe(
      es.reportExports.status.completed,
    )
    expect(wrapper.find(`[data-test="download-${UUID}-1"]`).exists()).toBe(true)
    expect(wrapper.text()).toContain(es.reportExports.kind.payroll)

    expect(wrapper.find(`[data-test="status-${UUID}-2"]`).text()).toBe(
      es.reportExports.status.running,
    )
    expect(wrapper.find(`[data-test="download-${UUID}-2"]`).exists()).toBe(false)
  })

  it('los criterios se enseñan tal cual al desplegarlos', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/reports/exports': () =>
        jsonResponse({
          data: [
            reportExport({
              criteria: ['Un criterio de ejemplo.', 'Otro criterio de ejemplo.'],
            }),
          ],
        }),
    })

    const wrapper = await mountPanel()

    const criteria = wrapper.find(`[data-test="criteria-${UUID}"]`)

    expect(criteria.text()).toContain('Un criterio de ejemplo.')
    expect(criteria.text()).toContain('Otro criterio de ejemplo.')
  })

  it('sondea cada 10 s SOLO mientras hay algo pendiente o en curso', async () => {
    vi.useFakeTimers()

    let listCalls = 0

    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/reports/exports': () => {
        listCalls += 1

        return jsonResponse({ data: [reportExport({ status: 'running' })] })
      },
    })

    await mountPanelRaw()
    await vi.advanceTimersByTimeAsync(0)

    expect(listCalls).toBe(1)

    await vi.advanceTimersByTimeAsync(10_000)
    expect(listCalls).toBe(2)

    await vi.advanceTimersByTimeAsync(10_000)
    expect(listCalls).toBe(3)
  })

  it('sin nada pendiente ni en curso, no vuelve a sondear', async () => {
    vi.useFakeTimers()

    let listCalls = 0

    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/reports/exports': () => {
        listCalls += 1

        return jsonResponse({ data: [reportExport({ status: 'completed' })] })
      },
    })

    await mountPanelRaw()
    await vi.advanceTimersByTimeAsync(0)

    expect(listCalls).toBe(1)

    await vi.advanceTimersByTimeAsync(40_000)
    expect(listCalls).toBe(1)
  })

  it('se desmonta y deja de sondear: nada de peticiones fantasma tras salir de la pantalla', async () => {
    vi.useFakeTimers()

    let listCalls = 0

    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/reports/exports': () => {
        listCalls += 1

        return jsonResponse({ data: [reportExport({ status: 'pending' })] })
      },
    })

    const wrapper = await mountPanelRaw()
    await vi.advanceTimersByTimeAsync(0)

    expect(listCalls).toBe(1)

    wrapper.unmount()

    await vi.advanceTimersByTimeAsync(60_000)
    expect(listCalls).toBe(1)
  })

  it('«Descargar» pide un enlace nuevo cada vez, nunca reutiliza el anterior', async () => {
    let statusCalls = 0
    const tokens: string[] = []

    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/download': (url) => {
        const token = new URL(url, 'http://localhost').searchParams.get('token') ?? ''

        tokens.push(token)

        return new Response('contenido-de-prueba', {
          status: 200,
          headers: {
            'Content-Type': 'text/csv',
            'Content-Disposition': `attachment; filename=kronoqr-horas.csv`,
          },
        })
      },
      [`/reports/exports/${UUID}`]: () => {
        statusCalls += 1

        return jsonResponse({
          data: reportExport({
            download: {
              url: `/api/v1/reports/exports/${UUID}/download?token=token-${statusCalls}`,
              expires_at: '2026-07-01T08:15:00.000000Z',
            },
          }),
        })
      },
      '/reports/exports': () => jsonResponse({ data: [reportExport()] }),
    })

    const createObjectURL = vi.fn(() => 'blob:kronoqr')
    const revokeObjectURL = vi.fn()

    Object.defineProperty(URL, 'createObjectURL', {
      value: createObjectURL,
      writable: true,
      configurable: true,
    })
    Object.defineProperty(URL, 'revokeObjectURL', {
      value: revokeObjectURL,
      writable: true,
      configurable: true,
    })
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    const wrapper = await mountPanel()

    await wrapper.find(`[data-test="download-${UUID}"]`).trigger('click')
    await settle()

    expect(statusCalls).toBe(1)
    expect(tokens).toEqual(['token-1'])

    await wrapper.find(`[data-test="download-${UUID}"]`).trigger('click')
    await settle()

    expect(statusCalls).toBe(2)
    expect(tokens).toEqual(['token-1', 'token-2'])

    Reflect.deleteProperty(URL as unknown as object, 'createObjectURL')
    Reflect.deleteProperty(URL as unknown as object, 'revokeObjectURL')
  })

  it('un enlace usado o caducado (410) se explica en la pantalla, y no deja el botón bloqueado', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/download': () => problemResponse(410, 'urn:kronoqr:problem:report-export-link-used'),
      [`/reports/exports/${UUID}`]: () =>
        jsonResponse({
          data: reportExport({
            download: {
              url: `/api/v1/reports/exports/${UUID}/download?token=usado`,
              expires_at: '2026-07-01T08:15:00.000000Z',
            },
          }),
        }),
      '/reports/exports': () => jsonResponse({ data: [reportExport()] }),
    })

    const wrapper = await mountPanel()

    await wrapper.find(`[data-test="download-${UUID}"]`).trigger('click')
    await settle()

    expect(wrapper.find('[data-test="download-error"]').exists()).toBe(true)
    expect(wrapper.find<HTMLButtonElement>(`[data-test="download-${UUID}"]`).element.disabled).toBe(
      false,
    )
  })
})
