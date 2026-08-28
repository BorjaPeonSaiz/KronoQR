import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import ConnectionStatusBadge from '@/shared/ui/ConnectionStatusBadge.vue'
import LanguageSelector from '@/shared/ui/LanguageSelector.vue'
import PrivacyNoticePanel from '@/shared/ui/PrivacyNoticePanel.vue'
import { readPrivacyNoticeConfig } from '@/shared/config/privacy'
import { createAppI18n, initialLocale, readStoredLocale } from '@/shared/i18n'

describe('indicador de estado de conexion', () => {
  it('dice que hay red', () => {
    const wrapper = mount(ConnectionStatusBadge, {
      props: { status: 'online' },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.text()).toContain('En línea')
    expect(wrapper.attributes('role')).toBe('status')
  })

  it('dice cuantos quedan pendientes cuando no la hay', () => {
    const wrapper = mount(ConnectionStatusBadge, {
      props: { status: 'offline', pendingCount: 3 },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.text()).toContain('Sin conexión')
    expect(wrapper.text()).toContain('3 fichajes pendientes')
  })

  it('usa el singular con uno solo', () => {
    const wrapper = mount(ConnectionStatusBadge, {
      props: { status: 'offline', pendingCount: 1 },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.text()).toContain('1 fichaje pendiente')
  })

  it('dice que esta sincronizando mientras drena la cola', () => {
    const wrapper = mount(ConnectionStatusBadge, {
      props: { status: 'online', pendingCount: 4, syncing: true },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.text()).toContain('Sincronizando')
    expect(wrapper.text()).toContain('4 fichajes pendientes')
  })

  it('no dice «sincronizando» con la cola vacia: el indicador se tiene que poder creer', () => {
    const wrapper = mount(ConnectionStatusBadge, {
      props: { status: 'online', pendingCount: 0, syncing: true },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.text()).toContain('En línea')
    expect(wrapper.text()).not.toContain('Sincronizando')
  })

  it('se traduce al ingles', () => {
    const wrapper = mount(ConnectionStatusBadge, {
      props: { status: 'offline', pendingCount: 2 },
      global: { plugins: [createAppI18n('en')] },
    })

    expect(wrapper.text()).toContain('Offline')
    expect(wrapper.text()).toContain('2 records pending')
  })

  it('no comunica el estado solo con color', () => {
    const online = mount(ConnectionStatusBadge, {
      props: { status: 'online' },
      global: { plugins: [createAppI18n('es')] },
    })
    const offline = mount(ConnectionStatusBadge, {
      props: { status: 'offline' },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(online.text()).not.toBe(offline.text())
  })
})

describe('selector de idioma', () => {
  beforeEach(() => localStorage.clear())

  it('ofrece los dos idiomas con objetivos tactiles de 48 px', () => {
    const wrapper = mount(LanguageSelector, { global: { plugins: [createAppI18n('es')] } })
    const buttons = wrapper.findAll('button')

    expect(buttons).toHaveLength(2)
    for (const button of buttons) {
      expect(button.classes()).toContain('kiosk-touch')
    }
  })

  it('cambia el idioma y lo recuerda para la proxima vez', async () => {
    const i18n = createAppI18n('es')
    const wrapper = mount(LanguageSelector, { global: { plugins: [i18n] } })

    await wrapper.findAll('button')[1]?.trigger('click')

    expect(i18n.global.locale.value).toBe('en')
    expect(readStoredLocale()).toBe('en')
    expect(document.documentElement.lang).toBe('en')
  })

  it('la preferencia guardada gana a la del navegador', () => {
    localStorage.setItem('kronoqr.kiosk.locale', 'en')
    expect(initialLocale(['es-ES'])).toBe('en')
  })

  it('sin preferencia guardada, detecta la del navegador', () => {
    expect(initialLocale(['en-GB', 'es-ES'])).toBe('en')
    expect(initialLocale(['fr-FR'])).toBe('es')
  })

  it('marca cual esta activo para quien navega con lector de pantalla', () => {
    const wrapper = mount(LanguageSelector, { global: { plugins: [createAppI18n('es')] } })
    const buttons = wrapper.findAll('button')

    expect(buttons[0]?.attributes('aria-pressed')).toBe('true')
    expect(buttons[1]?.attributes('aria-pressed')).toBe('false')
  })
})

describe('aviso de privacidad (RF-KI-09, RL-09)', () => {
  const config = readPrivacyNoticeConfig({
    VITE_PRIVACY_CONTROLLER: 'Hotel Ejemplo S.L.',
    VITE_PRIVACY_POLICY_URL: 'https://ejemplo.test/privacidad',
  })

  it('esta SIEMPRE en pantalla, no detras de un boton', () => {
    const wrapper = mount(PrivacyNoticePanel, {
      props: { config },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.get('[data-testid="privacy-notice"]').isVisible()).toBe(true)
  })

  it('lleva los elementos del articulo 13 en capa 1', () => {
    const wrapper = mount(PrivacyNoticePanel, {
      props: { config },
      global: { plugins: [createAppI18n('es')] },
    })
    const text = wrapper.text()

    expect(text).toContain('Hotel Ejemplo S.L.')
    expect(text).toContain('Finalidad')
    expect(text).toContain('Base jurídica')
    expect(text).toContain('Conservación')
    expect(text).toContain('Derechos')
    expect(text).toContain('https://ejemplo.test/privacidad')
  })

  it('dice explicitamente que no hay biometria (ADR-009)', () => {
    const wrapper = mount(PrivacyNoticePanel, {
      props: { config },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.text()).toContain('no trata datos biométricos')
  })

  it('sigue apareciendo aunque falte la configuracion del cliente', () => {
    const wrapper = mount(PrivacyNoticePanel, {
      props: { config: readPrivacyNoticeConfig({}) },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.get('[data-testid="privacy-notice"]').isVisible()).toBe(true)
    expect(wrapper.text()).toContain('la empresa titular de este centro')
  })

  it('se traduce al ingles', () => {
    const wrapper = mount(PrivacyNoticePanel, {
      props: { config },
      global: { plugins: [createAppI18n('en')] },
    })

    expect(wrapper.text()).toContain('Data protection notice')
    expect(wrapper.text()).toContain('Legal basis')
  })

  it('mantiene el objetivo tactil de 48 px en los controles aunque el texto sea pequeno', () => {
    const wrapper = mount(PrivacyNoticePanel, {
      props: { config },
      global: { plugins: [createAppI18n('es')] },
    })

    const link = wrapper.find('a')
    const button = wrapper.find('button')
    expect(link.classes()).toContain('kiosk-touch')
    expect(button.classes()).toContain('kiosk-touch')
  })
})

describe('configuracion del aviso', () => {
  it('no admite una URL que no sea http(s)', () => {
    expect(
      readPrivacyNoticeConfig({ VITE_PRIVACY_POLICY_URL: 'javascript:alert(1)' }).policyUrl,
    ).toBeNull()
  })

  it('ignora los valores en blanco', () => {
    expect(readPrivacyNoticeConfig({ VITE_PRIVACY_CONTROLLER: '   ' }).controller).toBeNull()
  })

  it('acepta lo que si es configuracion valida', () => {
    expect(
      readPrivacyNoticeConfig({
        VITE_PRIVACY_CONTROLLER: 'Hotel Ejemplo S.L.',
        VITE_PRIVACY_POLICY_URL: 'https://ejemplo.test/privacidad',
      }),
    ).toEqual({ controller: 'Hotel Ejemplo S.L.', policyUrl: 'https://ejemplo.test/privacidad' })
  })
})
