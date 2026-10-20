// Aviso de privacidad del portal (D8, V3-PO-4, art. 13 RGPD).
import { describe, expect, it } from 'vitest'
import LoginView from '@/features/login/LoginView.vue'
import PrivacyNotice from '@/shared/ui/PrivacyNotice.vue'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'
import { mountView } from './support/harness'

const NOTICE = { controllerName: 'Hotel Marina, S. L.', policyUrl: 'https://hotelmarina.example/p' }

describe('PrivacyNotice', () => {
  it('con responsable y politica configurados los enseña, y la politica es un enlace', async () => {
    const wrapper = await mountView(PrivacyNotice, { props: { notice: NOTICE } })

    expect(wrapper.text()).toContain('Responsable: Hotel Marina, S. L.')
    expect(wrapper.find('a').attributes('href')).toBe(NOTICE.policyUrl)
    expect(wrapper.find('a').attributes('rel')).toContain('noopener')
  })

  it('sin configurar enseña la redaccion generica y no inventa ni un responsable ni un enlace', async () => {
    const wrapper = await mountView(PrivacyNotice, {
      props: { notice: { controllerName: null, policyUrl: null } },
    })

    expect(wrapper.text()).toContain(es.privacy.controllerUnknown)
    expect(wrapper.text()).toContain(es.privacy.policyPending)
    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('no enlaza una politica que no sea https', async () => {
    const wrapper = await mountView(PrivacyNotice, {
      props: { notice: { controllerName: null, policyUrl: 'javascript:alert(1)' } },
    })

    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('esta en español e ingles con las mismas claves', () => {
    expect(Object.keys(en.privacy).sort()).toEqual(Object.keys(es.privacy).sort())
  })

  it('aparece en la pantalla de acceso, con su encabezado asociado', async () => {
    const wrapper = await mountView(LoginView)
    const section = wrapper.find('[data-test="privacy-notice"]')

    expect(section.exists()).toBe(true)
    expect(section.attributes('aria-labelledby')).toBe('privacy-heading')
    expect(section.find('#privacy-heading').text()).toBe(es.privacy.heading)
  })
})
