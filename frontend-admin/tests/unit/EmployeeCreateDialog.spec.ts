import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import EmployeeCreateDialog from '@/features/employees/EmployeeCreateDialog.vue'
import es from '@/shared/i18n/locales/es.json'
import { loadBrandingWithLocales } from './support/branding'
import {
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

// Alta de empleado: el campo «idioma» solo existe si la instalacion ofrece mas
// de uno (bloque 26 de la 2.2.1; RF-GP-01, RF-PD-01).

beforeEach(() => {
  window.localStorage.clear()
  createTestPinia()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

async function mountAndSubmit(available: readonly string[]) {
  await loadBrandingWithLocales(available)

  const spy = stubFetch((url) =>
    url.startsWith('/api/v1/departments') ? jsonResponse({ data: [] }) : jsonResponse({}, 201),
  )
  const wrapper = await mountView(EmployeeCreateDialog, { props: { timezone: 'Europe/Madrid' } })

  await settle()

  return { wrapper, spy }
}

function postedBody(spy: ReturnType<typeof vi.fn>): Record<string, unknown> {
  const call = spy.mock.calls.find(
    ([url, init]) => String(url) === '/api/v1/employees' && init?.method === 'POST',
  )

  return JSON.parse(String(call?.[1]?.body)) as Record<string, unknown>
}

describe('EmployeeCreateDialog', () => {
  it('con un solo idioma no enseña el campo y no envia locale', async () => {
    const { wrapper, spy } = await mountAndSubmit(['es'])

    expect(wrapper.text()).not.toContain(es.employees.fields.locale)

    await wrapper.find('form').trigger('submit')
    await settle()

    expect(postedBody(spy)).not.toHaveProperty('locale')
  })

  it('con dos idiomas ofrece exactamente esos y envia el elegido', async () => {
    const { wrapper, spy } = await mountAndSubmit(['es', 'en'])

    expect(wrapper.text()).toContain(es.employees.fields.locale)

    const select = wrapper.findAll('select').find((s) => s.find('option[value="en"]').exists())

    expect(select?.findAll('option').map((o) => o.element.getAttribute('value'))).toEqual([
      'es',
      'en',
    ])

    await select?.setValue('en')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(postedBody(spy)).toMatchObject({ locale: 'en' })
  })
})

// Alta con fecha futura y alta de alguien que ya tiene ficha de baja (RN-27,
// ADR-062 §6; avisos del propietario del 31-10-2026).
describe('EmployeeCreateDialog: fecha futura y persona de baja (RN-27)', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('con fecha de alta futura avisa de que los fichajes anteriores se rechazan, sin bloquear', async () => {
    vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-31T10:00:00Z') })

    const { wrapper } = await mountAndSubmit(['es'])

    expect(wrapper.find('[data-test="create-future-hire"]').exists()).toBe(false)

    await wrapper.find('input[type="date"]').setValue('2026-11-15')
    await settle(1)

    const warning = wrapper.find('[data-test="create-future-hire"]')

    expect(warning.text()).toContain('los fichajes anteriores a esa fecha se rechazan')
    expect(warning.text()).toContain('15 nov 2026')
    expect(warning.attributes('role')).toBe('status')
    expect(wrapper.find('button[type="submit"]').attributes('aria-disabled')).toBeUndefined()
  })

  it('el documento de una persona de baja ofrece darla de alta de nuevo en su ficha, no un error generico', async () => {
    const uuid = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'

    await loadBrandingWithLocales(['es'])
    stubFetch((url) =>
      url.startsWith('/api/v1/departments')
        ? jsonResponse({ data: [] })
        : problemResponse(409, 'urn:kronoqr:problem:conflict', { rehirable_employee_uuid: uuid }),
    )

    const wrapper = await mountView(EmployeeCreateDialog, { props: { timezone: 'Europe/Madrid' } })

    await settle()
    await wrapper.find('form').trigger('submit')
    await settle()

    const notice = wrapper.find('[data-test="rehirable-notice"]')

    expect(notice.attributes('role')).toBe('alert')
    expect(notice.text()).toContain(es.employees.create.rehirableTitle)
    expect(notice.text()).toContain(es.employees.create.rehirableAdvice)
    expect(wrapper.find('[data-test="rehirable-link"]').attributes('href')).toBe(
      `/employees/${uuid}`,
    )
    // No se repite el aviso generico de conflicto.
    expect(wrapper.text()).not.toContain(es.errors.conflict.title)
  })

  it('un 409 de correo duplicado sigue siendo el aviso general, sin enlace', async () => {
    await loadBrandingWithLocales(['es'])
    stubFetch((url) =>
      url.startsWith('/api/v1/departments')
        ? jsonResponse({ data: [] })
        : problemResponse(409, 'urn:kronoqr:problem:conflict'),
    )

    const wrapper = await mountView(EmployeeCreateDialog, { props: { timezone: 'Europe/Madrid' } })

    await settle()
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.find('[data-test="rehirable-notice"]').exists()).toBe(false)
    expect(wrapper.text()).toContain(es.errors.conflict.title)
  })
})
