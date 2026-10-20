// Descarga de un periodo sin jornadas y avisos que no suenan dos veces
// (V3-PO-7, V3-PO-8).
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import MyExportView from '@/features/my-export/MyExportView.vue'
import es from '@/shared/i18n/locales/es.json'
import { mountView, settle, stubFetch } from './support/harness'

function fileResponse(rows: string | null): Response {
  return new Response('x', {
    status: 200,
    headers: {
      'Content-Type': 'application/pdf',
      'Content-Disposition': 'attachment; filename="mi-registro-horario.pdf"',
      ...(rows === null ? {} : { 'X-Kronoqr-Report-Rows': rows }),
    },
  })
}

beforeEach(() => {
  clearAnnouncement()
  vi.stubGlobal('URL', {
    ...URL,
    createObjectURL: vi.fn(() => 'blob:kronoqr'),
    revokeObjectURL: vi.fn(),
  })
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

async function download(rows: string | null) {
  stubFetch(() => fileResponse(rows))
  const wrapper = await mountView(MyExportView)

  await wrapper.find('form').trigger('submit')
  await settle()

  return wrapper
}

describe('MyExportView, periodo vacio', () => {
  it('con cero filas no dice «descarga generada»: dice que no hay jornadas', async () => {
    const wrapper = await download('0')

    expect(wrapper.find('[data-test="export-empty"]').text()).toBe(es.myExport.empty)
    expect(wrapper.text()).not.toContain(es.myExport.announce.done)
    expect(announcement.value).toBe(es.myExport.announce.empty)
  })

  it('con filas, o sin que el servidor lo diga, es la descarga normal', async () => {
    for (const rows of ['23', null]) {
      const wrapper = await download(rows)

      expect(wrapper.find('[data-test="export-done"]').text()).toBe(es.myExport.announce.done)
      expect(wrapper.find('[data-test="export-empty"]').exists()).toBe(false)
    }
  })

  it('el texto visible no es una segunda region viva: no suena dos veces', async () => {
    const wrapper = await download('23')

    expect(wrapper.find('[data-test="export-done"]').attributes('role')).toBeUndefined()
    expect(wrapper.find('[data-test="export-done"]').attributes('aria-live')).toBeUndefined()
    expect(announcement.value).toBe(es.myExport.announce.done)
  })
})
