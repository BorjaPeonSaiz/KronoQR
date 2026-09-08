// «Tus datos son tuyos» (RF-PD-14, RL-20, ADR-019, regla dura 15): la
// exportacion integra de todos los datos de la instalacion.
//
// Lo que puede salir mal el dia que alguien la usa:
//
//  - Que se pida sin avisar de que el fichero lleva TODOS los datos
//    personales de la plantilla, o que el aviso no sea `role="alert"`.
//  - Que una segunda peticion mientras hay una en curso (409) se trate como un
//    fallo en vez de enseñar la fila que ya existe.
//  - Que el panel siga sondeando el servidor cuando ya no hay nada en curso.
//  - Que el contenido del ZIP se interprete en vez de solo descargarse.
//  - Que un rol sin `settings:*` vea el formulario que el servidor rechazaria.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { DOMWrapper } from '@vue/test-utils'
import DataExportPanel from '@/features/settings/DataExportPanel.vue'
import { useSessionStore } from '@/features/auth/session.store'
import es from '@/shared/i18n/locales/es.json'
import type { DataExport } from '@/shared/api/types'
import { clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { ADMIN_UUID, SITE, managementUser } from './support/fixtures'
import {
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubRoutes,
} from './support/harness'

const EXPORT_UUID = '0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f61'

function dataExportRow(overrides: Partial<DataExport> = {}): DataExport {
  return {
    uuid: EXPORT_UUID,
    status: 'completed',
    requested_via: 'panel',
    requested_by: { uuid: ADMIN_UUID, name: 'Dirección del hotel' },
    requested_at: '2026-09-08T10:15:00.000000Z',
    started_at: '2026-09-08T10:15:02.000000Z',
    completed_at: '2026-09-08T10:16:40.000000Z',
    failed_at: null,
    failure_reason: null,
    file_name: 'kronoqr-export-2.2.0-20260908T101640Z.zip',
    size_bytes: 15_728_640,
    sha256: '0'.repeat(64),
    row_counts: { employees: 62, shift_entries: 48210 },
    expires_at: '2026-09-15T10:16:40.000000Z',
    purged_at: null,
    downloaded_at: null,
    download_count: 0,
    ...overrides,
  }
}

/**
 * Monta el panel con una sesion ya autenticada, `admin` con `settings:*` por
 * omision. NO espera a que se resuelvan las consultas (`settle()` usa
 * temporizadores reales): las pruebas de sondeo, con el reloj falso puesto,
 * avanzan el reloj ellas mismas en su lugar.
 */
async function mountPanelRaw(
  abilities: string[] = ['settings:*'],
): Promise<Awaited<ReturnType<typeof mountView>>> {
  const pinia = createTestPinia()
  const session = useSessionStore(pinia)

  session.token = 'un-token'
  session.status = 'authenticated'
  session.user = managementUser({ roles: ['admin'], abilities })

  return mountView(DataExportPanel, { pinia })
}

/** Igual que `mountPanelRaw`, pero espera a que las consultas iniciales se resuelvan. */
async function mountPanel(
  abilities: string[] = ['settings:*'],
): Promise<Awaited<ReturnType<typeof mountView>>> {
  const wrapper = await mountPanelRaw(abilities)

  await settle()

  return wrapper
}

/** El boton con ESE texto exacto dentro del dialogo abierto: evita coincidir con «Generar exportación completa». */
function confirmDialogButton(wrapper: Awaited<ReturnType<typeof mountView>>): DOMWrapper<Element> {
  const dialog = wrapper.find('[role="dialog"]')
  const found = dialog
    .findAll('button')
    .find((button) => button.text().trim() === es.dataExport.confirm.action)

  if (found === undefined) {
    throw new Error('No se encuentra el botón de confirmación dentro del diálogo')
  }

  return found
}

let downloadedFilenames: string[]
let createObjectURL: ReturnType<typeof vi.fn>
let revokeObjectURL: ReturnType<typeof vi.fn>

beforeEach(() => {
  clearAnnouncement()
  downloadedFilenames = []
  createObjectURL = vi.fn(() => 'blob:kronoqr')
  revokeObjectURL = vi.fn()
  // Igual que `SupportView.spec.ts`: se AÑADEN los dos metodos al `URL` real,
  // sin sustituirlo entero, porque `stubRoutes` usa `new URL(...)` para leer
  // la ruta de cada peticion.
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
  // `downloadDocument` fija `anchor.download` justo antes de `click()`: leerlo
  // aqui es la unica forma de comprobar, desde fuera, con que nombre se llamo
  // sin interpretar el contenido del documento (que aqui ni siquiera hace
  // falta que exista de verdad).
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
    this: HTMLAnchorElement,
  ) {
    downloadedFilenames.push(this.download)
  })
})

afterEach(() => {
  Reflect.deleteProperty(URL as unknown as object, 'createObjectURL')
  Reflect.deleteProperty(URL as unknown as object, 'revokeObjectURL')
  vi.restoreAllMocks()
  vi.useRealTimers()
})

describe('DataExportPanel', () => {
  it('sin ámbito settings:*, no se renderiza (cortesía, regla dura 18)', async () => {
    const wrapper = await mountPanel([])

    expect(wrapper.find('[data-test="data-export"]').exists()).toBe(false)
  })

  it('el vacío explica que todavía no se ha pedido ninguna exportación', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/data-export': () => jsonResponse({ data: [] }),
    })

    const wrapper = await mountPanel()

    expect(wrapper.find('[data-test="data-export"]').exists()).toBe(true)
    expect(wrapper.text()).toContain(es.dataExport.list.empty.title)
  })

  it('enseña cada estado con lo que le corresponde: descarga solo si está completa, motivo si falló, aviso si caducó', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/data-export': () =>
        jsonResponse({
          data: [
            dataExportRow({ uuid: `${EXPORT_UUID}-1`, status: 'completed' }),
            dataExportRow({
              uuid: `${EXPORT_UUID}-2`,
              status: 'failed',
              completed_at: null,
              failed_at: '2026-09-08T10:20:00.000000Z',
              failure_reason: 'write_failed',
              size_bytes: null,
              row_counts: {},
            }),
            dataExportRow({
              uuid: `${EXPORT_UUID}-3`,
              status: 'purged',
              purged_at: '2026-09-16T00:00:00.000000Z',
            }),
            dataExportRow({
              uuid: `${EXPORT_UUID}-4`,
              status: 'pending',
              requested_by: null,
              started_at: null,
              completed_at: null,
              file_name: null,
              size_bytes: null,
              row_counts: {},
              expires_at: null,
            }),
          ],
        }),
    })

    const wrapper = await mountPanel()

    // Completada: se puede descargar.
    expect(wrapper.find(`[data-test="download-${EXPORT_UUID}-1"]`).exists()).toBe(true)
    expect(wrapper.find(`[data-test="status-${EXPORT_UUID}-1"]`).text()).toBe(
      es.dataExport.status.completed,
    )

    // Completada: el tamaño se lee en unidades BINARIAS, igual que la consola
    // (`ProductExportAllCommand::humanBytes`/`DiskProbe`): 15 728 640 bytes
    // son exactamente 15 MiB.
    expect(
      wrapper.find(`[data-test="status-${EXPORT_UUID}-1"]`).element.closest('tr')?.textContent,
    ).toContain('15,0 MiB')

    // Fallida: el codigo ESTABLE traducido, nunca la clase de una excepcion.
    expect(wrapper.find(`[data-test="download-${EXPORT_UUID}-2"]`).exists()).toBe(false)
    expect(wrapper.text()).toContain(es.dataExport.list.failureReasons.write_failed)

    // Purgada: el aviso de que caducó, sin descarga.
    expect(wrapper.find(`[data-test="download-${EXPORT_UUID}-3"]`).exists()).toBe(false)
    expect(wrapper.text()).toContain(es.dataExport.list.purgedNotice)

    // Pedida desde la consola: «Consola», no un hueco.
    const row4 = wrapper.find(`[data-test="status-${EXPORT_UUID}-4"]`).element.closest('tr')

    expect(row4?.textContent).toContain(es.dataExport.requestedByConsole)
  })

  it.each([
    ['write_failed', es.dataExport.list.failureReasons.write_failed],
    ['database_error', es.dataExport.list.failureReasons.database_error],
    ['stale', es.dataExport.list.failureReasons.stale],
    ['unexpected', es.dataExport.list.failureReasons.unexpected],
    ['codigo_que_esta_pantalla_no_conoce', 'codigo_que_esta_pantalla_no_conoce'],
  ])(
    'traduce el codigo estable %s a un texto accionable, y un codigo desconocido se enseña tal cual',
    async (code, expectedText) => {
      stubRoutes({
        '/site': () => jsonResponse(SITE),
        '/data-export': () =>
          jsonResponse({
            data: [
              dataExportRow({
                status: 'failed',
                completed_at: null,
                failed_at: '2026-09-08T10:20:00.000000Z',
                failure_reason: code as DataExport['failure_reason'],
                size_bytes: null,
                row_counts: {},
              }),
            ],
          }),
      })

      const wrapper = await mountPanel()

      expect(wrapper.text()).toContain(expectedText)
      // Nunca la clase de una excepcion ni un mensaje de base de datos (regla
      // dura 21): si el codigo no es de los cuatro conocidos, se enseña tal
      // cual llega, sin traducirlo ni ocultarlo.
    },
  )

  it('el diálogo muestra el aviso role="alert" antes de pedir, y no manda nada hasta confirmar', async () => {
    let postCalls = 0

    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/data-export': (_url, init) => {
        if ((init?.method ?? 'GET') === 'POST') {
          postCalls += 1
        }

        return jsonResponse({ data: [] })
      },
    })

    const wrapper = await mountPanel()

    await wrapper.find('[data-test="open-generate"]').trigger('click')
    await settle()

    const warning = wrapper.find('[data-test="data-export-warning"]')

    expect(warning.exists()).toBe(true)
    expect(warning.attributes('role')).toBe('alert')
    expect(warning.text()).toBe(es.dataExport.confirm.warning)
    expect(postCalls).toBe(0)
  })

  it('tras un 409 con una exportación ya en curso, enseña esa fila en vez de fallar', async () => {
    const inProgressRow = dataExportRow({
      status: 'running',
      completed_at: null,
      size_bytes: null,
      row_counts: {},
      requested_at: '2026-09-08T10:15:00.000000Z',
    })

    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/data-export': (_url, init) => {
        if ((init?.method ?? 'GET') === 'POST') {
          return problemResponse(409, 'urn:kronoqr:problem:data-export-in-progress', {
            export: inProgressRow,
          })
        }

        return jsonResponse({ data: [] })
      },
    })

    const wrapper = await mountPanel()

    await wrapper.find('[data-test="open-generate"]').trigger('click')
    await settle()
    await confirmDialogButton(wrapper).trigger('click')
    await settle()

    // Sin dialogo de error: la fila devuelta se enseña como si fuera la
    // recien creada, no como un fallo.
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(wrapper.find(`[data-test="status-${EXPORT_UUID}"]`).text()).toBe(
      es.dataExport.status.running,
    )
    expect(wrapper.find('[data-test="active-notice"]').exists()).toBe(true)
    // Y no se puede pedir otra mientras esta siga en curso.
    expect(wrapper.find('[data-test="open-generate"]').attributes('disabled')).toBeDefined()
  })

  it('sondea la lista cada 5 s SOLO mientras hay una exportación pendiente o en curso', async () => {
    vi.useFakeTimers()

    let listCalls = 0

    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/data-export': (_url, init) => {
        if ((init?.method ?? 'GET') === 'GET') {
          listCalls += 1
        }

        return jsonResponse({ data: [dataExportRow({ status: 'running' })] })
      },
    })

    await mountPanelRaw()
    // `settle()` usa temporizadores reales y aqui estan sustituidos: se
    // avanza el reloj falso en su lugar para dejar que la primera peticion
    // (microtareas del `fetch` simulado) se resuelva.
    await vi.advanceTimersByTimeAsync(0)

    expect(listCalls).toBe(1)

    await vi.advanceTimersByTimeAsync(5_000)
    expect(listCalls).toBe(2)

    await vi.advanceTimersByTimeAsync(5_000)
    expect(listCalls).toBe(3)
  })

  it('sin ninguna exportación en curso, no vuelve a sondear', async () => {
    vi.useFakeTimers()

    let listCalls = 0

    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/data-export': (_url, init) => {
        if ((init?.method ?? 'GET') === 'GET') {
          listCalls += 1
        }

        return jsonResponse({ data: [dataExportRow({ status: 'completed' })] })
      },
    })

    await mountPanelRaw()
    await vi.advanceTimersByTimeAsync(0)

    expect(listCalls).toBe(1)

    await vi.advanceTimersByTimeAsync(20_000)
    expect(listCalls).toBe(1)
  })

  it('descargar llama al documento con el nombre que trae Content-Disposition, sin interpretar el contenido', async () => {
    const filename = 'kronoqr-export-2.2.0-20260908T101640Z.zip'

    stubRoutes({
      '/data-export/0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f61/download': () =>
        new Response(new Blob(['contenido-de-prueba']), {
          status: 200,
          headers: {
            'Content-Type': 'application/zip',
            'Content-Disposition': `attachment; filename=${filename}`,
            'X-Kronoqr-Export-Sha256': '0'.repeat(64),
            'X-Kronoqr-Export-Rows': '48272',
          },
        }),
      '/site': () => jsonResponse(SITE),
      '/data-export': () => jsonResponse({ data: [dataExportRow({ status: 'completed' })] }),
    })

    const wrapper = await mountPanel()

    await wrapper.find(`[data-test="download-${EXPORT_UUID}"]`).trigger('click')
    await settle()

    expect(downloadedFilenames).toEqual([filename])
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:kronoqr')
  })
})
