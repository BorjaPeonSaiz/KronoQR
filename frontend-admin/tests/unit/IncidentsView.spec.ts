// Bandeja de incidencias (RF-PA-05, RF-PR-01): filtros al servidor, paginacion,
// vacio, error, y que un refresco fallido o un filtro fallido nunca dejan datos
// de otro conjunto sin decirlo (V21-3-PA-2, V3-PA-01, R3-PA-02, V3-PA-06).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import IncidentsView from '@/features/incidents/IncidentsView.vue'
import { useIncidentsStore } from '@/features/incidents/incidents.store'
import es from '@/shared/i18n/locales/es.json'
import { incident, incidentCollection } from './support/fixtures'
import { jsonResponse, mountView, settle, stubFetch } from './support/harness'

describe('IncidentsView', () => {
  let urls: string[]
  let failing: boolean
  let collection = incidentCollection()

  beforeEach(() => {
    urls = []
    failing = false
    collection = incidentCollection()
    stubFetch((url) => {
      if (url.includes('/api/v1/departments')) {
        return jsonResponse({ data: [{ id: 3, name: 'Cocina' }] })
      }

      urls.push(url)

      return failing ? jsonResponse({}, 503) : jsonResponse(collection)
    })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('carga lo pendiente al entrar y enseña la hora de lectura con la zona del centro', async () => {
    const wrapper = await mountView(IncidentsView)
    await settle()

    expect(urls[0]).toContain('status=open')
    expect(wrapper.findAll('[data-test="incident-row"]')).toHaveLength(1)
    // 08:00 UTC son las 09:00 en Europe/Madrid en marzo, sea cual sea la zona del navegador.
    expect(wrapper.find('[data-test="read-at"]').text()).toBe(
      es.incidents.refresh.readAt.replace('{moment}', '15/3/26, 9:00 (Europe/Madrid)'),
    )

    wrapper.unmount()
  })

  it('manda los filtros al servidor y vuelve a la primera pagina', async () => {
    const wrapper = await mountView(IncidentsView)
    await settle()

    await wrapper.find('#incidents-severity-filter').setValue('high')
    await settle()

    expect(urls.at(-1)).toContain('severity=high')
    expect(urls.at(-1)).toContain('page=1')

    await wrapper.find('#incidents-status-filter').setValue('resolved')
    await settle()

    expect(urls.at(-1)).toContain('status=resolved')

    wrapper.unmount()
  })

  it('sin incidencias enseña el vacio del estado elegido, no una tabla vacia', async () => {
    collection = incidentCollection([])
    const wrapper = await mountView(IncidentsView)
    await settle()

    expect(wrapper.text()).toContain(es.incidents.empty.open.title)
    expect(wrapper.find('[data-test="incident-table"]').exists()).toBe(false)

    wrapper.unmount()
  })

  it('si la primera carga falla enseña el error y el boton de actualizar para reintentar', async () => {
    failing = true
    const wrapper = await mountView(IncidentsView)
    await settle()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="incident-row"]').exists()).toBe(false)

    failing = false
    await wrapper.find('[data-test="refresh"]').trigger('click')
    await settle()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test="incident-row"]')).toHaveLength(1)

    wrapper.unmount()
  })

  it('un refresco fallido avisa con la hora de la ultima lectura buena y conserva las filas', async () => {
    const wrapper = await mountView(IncidentsView)
    await settle()

    failing = true
    await wrapper.find('[data-test="refresh"]').trigger('click')
    await settle()

    const notice = wrapper.find('[data-test="refresh-notice"]')

    expect(notice.attributes('data-kind')).toBe('refresh')
    expect(notice.attributes('role')).toBe('status')
    expect(notice.text()).toContain('15/3/26, 9:00 (Europe/Madrid)')
    expect(wrapper.findAll('[data-test="incident-row"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="read-at"]').text()).toContain('9:00')

    // Se retira al leer bien, y la hora de lectura avanza.
    failing = false
    collection = incidentCollection([incident()], { generated_at: '2026-03-15T08:30:00.000000Z' })
    await wrapper.find('[data-test="refresh"]').trigger('click')
    await settle()

    expect(wrapper.find('[data-test="refresh-notice"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="read-at"]').text()).toContain('9:30')

    wrapper.unmount()
  })

  it('un cambio de filtro fallido no deja las filas del filtro anterior bajo el filtro nuevo', async () => {
    const wrapper = await mountView(IncidentsView)
    await settle()

    failing = true
    await wrapper.find('#incidents-status-filter').setValue('resolved')
    await settle()

    expect(wrapper.find('[data-test="refresh-notice"]').attributes('data-kind')).toBe('filter')
    expect(wrapper.find('[data-test="incident-row"]').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    // Ni el vacio «no hay resueltas» del filtro nuevo: no se sabe si las hay.
    expect(wrapper.text()).not.toContain(es.incidents.empty.resolved.title)

    // Actualizar y fallar de nuevo no devuelve las filas viejas.
    await wrapper.find('[data-test="refresh"]').trigger('click')
    await settle()

    expect(wrapper.find('[data-test="incident-row"]').exists()).toBe(false)

    failing = false
    await wrapper.find('[data-test="refresh"]').trigger('click')
    await settle()

    expect(wrapper.find('[data-test="refresh-notice"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test="incident-row"]')).toHaveLength(1)

    wrapper.unmount()
  })

  it('un cambio de pagina fallido tampoco enseña la pagina anterior como si fuera la nueva', async () => {
    collection = incidentCollection([incident()], { total: 60, total_pages: 3 })
    const wrapper = await mountView(IncidentsView)
    await settle()

    failing = true
    await useIncidentsStore().goToPage(2)
    await settle()

    expect(wrapper.find('[data-test="incident-row"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="refresh-notice"]').attributes('data-kind')).toBe('filter')

    wrapper.unmount()
  })
})
