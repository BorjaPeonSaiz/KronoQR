import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import EmployeeCreateDialog from '@/features/employees/EmployeeCreateDialog.vue'
import es from '@/shared/i18n/locales/es.json'
import { loadBrandingWithLocales } from './support/branding'
import { createTestPinia, jsonResponse, mountView, settle, stubFetch } from './support/harness'

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
