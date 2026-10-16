import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CreateAccountDialog from '@/features/accounts/CreateAccountDialog.vue'
import es from '@/shared/i18n/locales/es.json'
import { loadBrandingWithLocales } from './support/branding'
import { createTestPinia, jsonResponse, mountView, settle, stubFetch } from './support/harness'

// Alta de cuenta de gestion: el campo «idioma» solo existe si la instalacion
// ofrece mas de uno (bloque 26 de la 2.2.1; RF-ID-10, RF-PD-01).

beforeEach(() => {
  window.localStorage.clear()
  createTestPinia()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

async function mountAndSubmit(available: readonly string[]) {
  await loadBrandingWithLocales(available)

  const spy = stubFetch(() => jsonResponse({}, 201))
  const wrapper = await mountView(CreateAccountDialog)

  await wrapper.find('[data-test="account-name"]').setValue('Ana Pons')
  await wrapper.find('[data-test="account-email"]').setValue('ana@hotel.example')
  await wrapper.find('[data-test="actor-reauth"]').setValue('123456')
  await wrapper.find('form').trigger('submit')
  await settle()

  return { wrapper, spy }
}

function postedBody(spy: ReturnType<typeof vi.fn>): Record<string, unknown> {
  const call = spy.mock.calls.find(
    ([url, init]) => String(url) === '/api/v1/management-accounts' && init?.method === 'POST',
  )

  return JSON.parse(String(call?.[1]?.body)) as Record<string, unknown>
}

describe('CreateAccountDialog', () => {
  it('con un solo idioma no enseña el campo y no envia locale', async () => {
    const { wrapper, spy } = await mountAndSubmit(['es'])

    expect(wrapper.find('[data-test="account-locale"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain(es.accounts.fields.locale)
    expect(postedBody(spy)).not.toHaveProperty('locale')
  })

  it('con dos idiomas ofrece exactamente esos y envia el idioma de la interfaz', async () => {
    const { wrapper, spy } = await mountAndSubmit(['es', 'en'])
    const options = wrapper.findAll('[data-test="account-locale"] option')

    expect(options.map((o) => o.element.getAttribute('value'))).toEqual(['es', 'en'])
    expect(postedBody(spy)).toMatchObject({ locale: 'es' })
  })
})
