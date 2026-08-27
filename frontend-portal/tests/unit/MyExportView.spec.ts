// Mi exportacion (RF-ID-05, RL-05, art. 20 RGPD).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import MyExportView from '@/features/my-export/MyExportView.vue'
import es from '@/shared/i18n/locales/es.json'
import { EMPLOYEE_UUID } from './support/fixtures'
import { mountView, settle, stubFetch } from './support/harness'

let requestedUrls: string[] = []

function csvResponse(): Response {
  return new Response('fecha;horas\n2026-03-14;08:05\n', {
    status: 200,
    headers: {
      'Content-Type': 'text/csv',
      'Content-Disposition': 'attachment; filename="mi-registro-horario.csv"',
    },
  })
}

beforeEach(() => {
  requestedUrls = []
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

describe('MyExportView', () => {
  it('pide el CSV en formato explicito y sin ningun identificador de empleado', async () => {
    stubFetch((url) => {
      requestedUrls.push(url)

      return csvResponse()
    })

    const wrapper = await mountView(MyExportView)

    await wrapper.find('form').trigger('submit')
    await settle()

    const url = requestedUrls.at(-1) ?? ''

    expect(url).toContain('/api/v1/me/export')
    expect(url).toContain('format=csv')
    expect(url).not.toContain(EMPLOYEE_UUID)
  })

  it('descarga el fichero y lo anuncia, sin dejarlo vivo en el navegador', async () => {
    stubFetch(() => csvResponse())

    const wrapper = await mountView(MyExportView)

    await wrapper.find('form').trigger('submit')
    await settle()

    expect(HTMLAnchorElement.prototype.click).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).toContain(es.myExport.announce.done)
  })

  it('consulta el periodo que se le pide', async () => {
    stubFetch((url) => {
      requestedUrls.push(url)

      return csvResponse()
    })

    const wrapper = await mountView(MyExportView)
    const inputs = wrapper.findAll('input[type="date"]')

    await inputs[0]?.setValue('2026-03-01')
    await inputs[1]?.setValue('2026-03-31')
    await wrapper.find('form').trigger('submit')
    await settle()

    const url = requestedUrls.at(-1) ?? ''

    expect(url).toContain('from=2026-03-01')
    expect(url).toContain('to=2026-03-31')
  })

  it('no deja descargar un periodo que termina antes de empezar', async () => {
    const spy = stubFetch(() => csvResponse())
    const wrapper = await mountView(MyExportView)
    const inputs = wrapper.findAll('input[type="date"]')

    await inputs[0]?.setValue('2026-03-31')
    await inputs[1]?.setValue('2026-03-01')
    await settle(1)

    expect(wrapper.text()).toContain(es.myExport.filters.inverted)
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()

    await wrapper.find('form').trigger('submit')
    await settle(1)

    expect(spy).not.toHaveBeenCalled()
  })

  it('cuenta que ha pasado y que hacer si la descarga falla', async () => {
    stubFetch(() => {
      throw new TypeError('Failed to fetch')
    })

    const wrapper = await mountView(MyExportView)

    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.title)
  })

  it('cada campo del formulario tiene su etiqueta asociada', async () => {
    stubFetch(() => csvResponse())
    const wrapper = await mountView(MyExportView)

    for (const input of wrapper.findAll('input')) {
      const id = input.attributes('id')

      expect(wrapper.findAll('label').some((label) => label.attributes('for') === id)).toBe(true)
    }
  })
})
