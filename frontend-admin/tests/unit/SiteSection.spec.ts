import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import SiteSection from '@/features/departments/SiteSection.vue'
import es from '@/shared/i18n/locales/es.json'
import { SITE } from './support/fixtures'
import {
  buttonWith,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

// El centro y su zona horaria (RN-23, ADR-056): editable hasta el primer fichaje,
// fija despues. El servidor es quien decide; la interfaz solo refleja
// `timezone_locked` y explica el `409` si el primer fichaje llega a mitad.

const LOCKED = { ...SITE, timezone_locked: true }

beforeEach(() => {
  clearAnnouncement()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

function patches(spy: ReturnType<typeof stubFetch>) {
  return spy.mock.calls.filter((call) => (call[1] as RequestInit | undefined)?.method === 'PATCH')
}

describe('SiteSection: zona horaria', () => {
  it('bloqueada: muestra la zona sin boton para cambiarla y con la nota de bloqueo', async () => {
    stubFetch(() => jsonResponse(LOCKED))

    const wrapper = await mountView(SiteSection)

    await settle()

    expect(wrapper.find('[data-test="site-timezone"]').text()).toBe('Europe/Madrid')
    expect(wrapper.find('[data-test="site-change-timezone"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="site-timezone-note"]').text()).toBe(es.site.timezoneLocked)
  })

  it('abierta: abre el dialogo con antes y despues, guarda solo la zona, anuncia y relee', async () => {
    let site = { ...SITE }
    const spy = stubFetch((_url, init) => {
      if (init?.method === 'PATCH') {
        site = { ...site, ...(JSON.parse(String(init.body)) as object) }
      }

      return jsonResponse(site)
    })

    const wrapper = await mountView(SiteSection)

    await settle()

    expect(wrapper.find('[data-test="site-timezone-note"]').text()).toBe(es.site.timezoneOpen)

    await wrapper.find('[data-test="site-change-timezone"]').trigger('click')

    const dialog = wrapper.find('[role="dialog"]')
    const confirm = buttonWith(dialog, es.site.changeTimezone)

    // Sin cambio no se puede confirmar.
    expect(confirm.attributes('disabled')).toBeDefined()

    await dialog.find('[data-test="timezone-input"]').setValue('Atlantic/Canary')

    expect(
      dialog.findAll('[data-test="timezone-preview"] tbody td').map((td) => td.text()),
    ).toEqual(['Europe/Madrid', 'Atlantic/Canary'])
    expect(patches(spy)).toHaveLength(0)

    await confirm.trigger('click')
    await settle()

    const patch = patches(spy)[0]

    expect(String(patch?.[0])).toBe('/api/v1/site')
    expect(JSON.parse(String((patch?.[1] as RequestInit).body))).toEqual({
      timezone: 'Atlantic/Canary',
    })
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(announcement.value).toBe('Zona horaria guardada: Atlantic/Canary.')
    expect(wrapper.find('[data-test="site-timezone"]').text()).toBe('Atlantic/Canary')
  })

  it('un 409 por zona fija explica lo ocurrido, cierra el dialogo y relee el centro', async () => {
    let locked = false
    const spy = stubFetch((_url, init) => {
      if (init?.method === 'PATCH') {
        locked = true

        return problemResponse(409, 'urn:kronoqr:problem:site-time-zone-locked')
      }

      return jsonResponse({ ...SITE, timezone_locked: locked })
    })

    const wrapper = await mountView(SiteSection)

    await settle()
    await wrapper.find('[data-test="site-change-timezone"]').trigger('click')
    await wrapper.find('[data-test="timezone-input"]').setValue('Europe/Lisbon')
    await buttonWith(wrapper.find('[role="dialog"]'), es.site.changeTimezone).trigger('click')
    await settle()

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="site-timezone-locked-error"]').text()).toBe(
      es.site.errors.timezoneLocked,
    )
    expect(announcement.value).toBe(es.site.errors.timezoneLocked)
    // Releido: ya no hay boton y la zona sigue siendo la anterior.
    expect(wrapper.find('[data-test="site-change-timezone"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="site-timezone"]').text()).toBe('Europe/Madrid')
    expect(wrapper.find('[data-test="site-timezone-note"]').text()).toBe(es.site.timezoneLocked)
    expect(spy.mock.calls.length - patches(spy).length).toBeGreaterThanOrEqual(2)
  })
})
