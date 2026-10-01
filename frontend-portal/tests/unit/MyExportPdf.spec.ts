// Descarga de mi historico en PDF (RF-ID-05, RL-05, PR19).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import MyExportView from '@/features/my-export/MyExportView.vue'
import es from '@/shared/i18n/locales/es.json'
import { mountView, problemResponse, settle, stubFetch } from './support/harness'

let requested: string[] = []
let downloadedNames: string[] = []

function pdfResponse(): Response {
  return new Response('%PDF-1.7 prueba', {
    status: 200,
    headers: {
      'Content-Type': 'application/pdf',
      'Content-Disposition': 'attachment; filename=mi-registro-horario-2026-03-01_2026-03-31.pdf',
    },
  })
}

function csvResponse(): Response {
  return new Response('fecha;horas\n', {
    status: 200,
    headers: {
      'Content-Type': 'text/csv',
      'Content-Disposition': 'attachment; filename=mi-registro-horario-2026-03-01_2026-03-31.csv',
    },
  })
}

beforeEach(() => {
  requested = []
  downloadedNames = []
  vi.stubGlobal('URL', {
    ...URL,
    createObjectURL: vi.fn(() => 'blob:kronoqr'),
    revokeObjectURL: vi.fn(),
  })
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
    this: HTMLAnchorElement,
  ) {
    downloadedNames.push(this.download)
  })
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('MyExportView, elegir CSV o PDF (RF-ID-05, RL-05)', () => {
  it('ofrece los dos formatos como un grupo de opciones con su leyenda, CSV elegido por omision', async () => {
    stubFetch(() => csvResponse())

    const wrapper = await mountView(MyExportView)
    const radios = wrapper.findAll('input[type="radio"]')

    expect(radios).toHaveLength(2)
    expect(wrapper.text()).toContain(es.myExport.format.legend)
    expect((radios[0]?.element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.find('button[type="submit"]').text()).toBe(es.myExport.download.csv)
    expect(wrapper.find('button[type="submit"]').classes()).toContain('min-h-12')
  })

  it('con PDF pide format=pdf y conserva el nombre de fichero del servidor', async () => {
    stubFetch((url) => {
      requested.push(url)

      return pdfResponse()
    })

    const wrapper = await mountView(MyExportView)

    await wrapper.findAll('input[type="radio"]')[1]?.setValue(true)
    expect(wrapper.find('button[type="submit"]').text()).toBe(es.myExport.download.pdf)

    await wrapper.find('form').trigger('submit')
    await settle()

    expect(requested.at(-1)).toContain('format=pdf')
    expect(downloadedNames).toEqual(['mi-registro-horario-2026-03-01_2026-03-31.pdf'])
    expect(wrapper.text()).toContain(es.myExport.announce.done)
  })

  it('un 503 del PDF avisa y ofrece el CSV, que descarga con su nombre', async () => {
    stubFetch((url) => {
      requested.push(url)

      return url.includes('format=pdf') ? problemResponse(503, 'about:blank') : csvResponse()
    })

    const wrapper = await mountView(MyExportView)

    await wrapper.findAll('input[type="radio"]')[1]?.setValue(true)
    await wrapper.find('form').trigger('submit')
    await settle()

    const notice = wrapper.find('[data-test="pdf-unavailable"]')

    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain(es.myExport.pdfUnavailable.title)
    expect(downloadedNames).toEqual([])

    await wrapper.find('[data-test="use-csv"]').trigger('click')
    await settle()

    expect(requested.at(-1)).toContain('format=csv')
    expect(downloadedNames).toEqual(['mi-registro-horario-2026-03-01_2026-03-31.csv'])
    expect(wrapper.find('[data-test="pdf-unavailable"]').exists()).toBe(false)
  })

  it.each([401, 403, 422, 429])(
    'un %s del PDF se explica con el aviso generico, no con el del PDF no disponible',
    async (status) => {
      stubFetch(() => problemResponse(status, 'about:blank'))

      const wrapper = await mountView(MyExportView)

      await wrapper.findAll('input[type="radio"]')[1]?.setValue(true)
      await wrapper.find('form').trigger('submit')
      await settle()

      expect(wrapper.find('[data-test="pdf-unavailable"]').exists()).toBe(false)
      expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    },
  )

  it('un 503 del CSV no se presenta como «PDF no disponible»', async () => {
    stubFetch(() => problemResponse(503, 'about:blank'))

    const wrapper = await mountView(MyExportView)

    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.find('[data-test="pdf-unavailable"]').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.unavailable.title)
  })
})
