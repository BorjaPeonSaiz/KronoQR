// @vitest-environment jsdom
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ConnectionStatusBadge from '@/shared/ui/ConnectionStatusBadge.vue'
import LanguageSelector from '@/shared/ui/LanguageSelector.vue'
import PrivacyNoticePanel from '@/shared/ui/PrivacyNoticePanel.vue'
import { createAppI18n, initialLocale, readStoredLocale } from '@/shared/i18n'

const BRANDING_KEY = 'kronoqr.kiosk.branding'
const RAW_BILINGUAL = {
  application_name: 'KronoQR',
  accent_color: null,
  logo_url: null,
  locales: { default: 'es', available: ['es', 'en'] },
  privacy_notice: { controller_name: null, policy_url: null },
}

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

const BILINGUAL = { default: 'es', available: ['es', 'en'] }
const SPANISH_ONLY = { default: 'es', available: ['es'] }

describe('selector de idioma', () => {
  beforeEach(() => localStorage.clear())

  it('ofrece los dos idiomas con objetivos tactiles de 48 px', () => {
    const wrapper = mount(LanguageSelector, {
      props: { policy: BILINGUAL },
      global: { plugins: [createAppI18n('es')] },
    })
    const buttons = wrapper.findAll('button')

    expect(buttons).toHaveLength(2)
    for (const button of buttons) {
      expect(button.classes()).toContain('kiosk-touch')
    }
  })

  it('cambia el idioma pero NO lo recuerda: la eleccion es de quien ficha (D9, V7-KI-05)', async () => {
    const i18n = createAppI18n('es')
    const wrapper = mount(LanguageSelector, {
      props: { policy: BILINGUAL },
      global: { plugins: [i18n] },
    })

    await wrapper.findAll('button')[1]?.trigger('click')

    expect(i18n.global.locale.value).toBe('en')
    expect(readStoredLocale()).toBeNull()
    expect(document.documentElement.lang).toBe('en')
  })

  it('la preferencia guardada gana a la del navegador', () => {
    localStorage.setItem('kronoqr.kiosk.locale', 'en')
    expect(initialLocale(['es-ES'], localStorage, BILINGUAL)).toBe('en')
  })

  it('sin preferencia guardada, detecta la del navegador', () => {
    expect(initialLocale(['en-GB', 'es-ES'], localStorage, BILINGUAL)).toBe('en')
    expect(initialLocale(['fr-FR'], localStorage, BILINGUAL)).toBe('es')
  })

  it('marca cual esta activo para quien navega con lector de pantalla', () => {
    const wrapper = mount(LanguageSelector, {
      props: { policy: BILINGUAL },
      global: { plugins: [createAppI18n('es')] },
    })
    const buttons = wrapper.findAll('button')

    expect(buttons[0]?.attributes('aria-pressed')).toBe('true')
    expect(buttons[1]?.attributes('aria-pressed')).toBe('false')
  })

  it('filtra los idiomas por la marca de la instalacion (RF-PD-08)', () => {
    const wrapper = mount(LanguageSelector, {
      props: { policy: { default: 'es', available: ['es', 'en'] } },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.findAll('button')).toHaveLength(2)
    expect(wrapper.text()).toContain('English')
  })

  it('con un solo idioma ofrecido, NO PINTA el selector (doc 06 regla 8: un control sin nada que elegir no se muestra)', () => {
    const wrapper = mount(LanguageSelector, {
      props: { policy: { default: 'es', available: ['es'] } },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.find('[role="group"]').exists()).toBe(false)
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('con un solo idioma ofrecido, el idioma activo cambia igual aunque no se pinte nada', async () => {
    const i18n = createAppI18n('en')
    const wrapper = mount(LanguageSelector, {
      props: { policy: { default: 'es', available: ['es', 'en'] } },
      global: { plugins: [i18n] },
    })
    expect(i18n.global.locale.value).toBe('en')

    await wrapper.setProps({ policy: { default: 'es', available: ['es'] } })

    expect(i18n.global.locale.value).toBe('es')
    expect(readStoredLocale()).toBeNull()
    expect(wrapper.find('[role="group"]').exists()).toBe(false)
  })

  it('sin `policy` y sin copia de la marca, es el producto: solo espanol, sin selector (RF-KI-05)', () => {
    const wrapper = mount(LanguageSelector, { global: { plugins: [createAppI18n('es')] } })

    expect(wrapper.find('[role="group"]').exists()).toBe(false)
  })

  it('sin `policy`, usa la de la copia guardada de la marca (RF-KI-05)', () => {
    localStorage.setItem(BRANDING_KEY, JSON.stringify(RAW_BILINGUAL))
    const wrapper = mount(LanguageSelector, { global: { plugins: [createAppI18n('es')] } })

    expect(wrapper.findAll('button')).toHaveLength(2)
  })

  it('cuando la marca abre un segundo idioma, el idioma activo NO se toca: la vuelta al de serie es de useLocaleResetAfterScan (D9)', async () => {
    const i18n = createAppI18n('es')
    const wrapper = mount(LanguageSelector, {
      props: { policy: SPANISH_ONLY },
      global: { plugins: [i18n] },
    })
    expect(i18n.global.locale.value).toBe('es')

    vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['en-GB'])
    await wrapper.setProps({ policy: BILINGUAL })

    expect(i18n.global.locale.value).toBe('es')
    expect(readStoredLocale()).toBeNull()
    vi.restoreAllMocks()
  })

  it('una politica nueva que sigue ofreciendo el idioma elegido no lo pisa a media confirmacion', async () => {
    const i18n = createAppI18n('en')
    const wrapper = mount(LanguageSelector, {
      props: { policy: BILINGUAL },
      global: { plugins: [i18n] },
    })

    await wrapper.setProps({ policy: { default: 'es', available: ['en', 'es'] } })

    expect(i18n.global.locale.value).toBe('en')
  })

  it('con una eleccion guardada, abrir un segundo idioma no la pisa (RF-KI-05)', async () => {
    localStorage.setItem('kronoqr.kiosk.locale', 'es')
    const i18n = createAppI18n('es')
    const wrapper = mount(LanguageSelector, {
      props: { policy: SPANISH_ONLY },
      global: { plugins: [i18n] },
    })

    vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['en-GB'])
    await wrapper.setProps({ policy: BILINGUAL })

    expect(i18n.global.locale.value).toBe('es')
    vi.restoreAllMocks()
  })
})

describe('idioma inicial segun la politica de la instalacion (RF-KI-05)', () => {
  beforeEach(() => localStorage.clear())

  it('con un solo idioma, ni el navegador ni la eleccion guardada lo cambian', () => {
    localStorage.setItem('kronoqr.kiosk.locale', 'en')
    expect(initialLocale(['en-GB'], localStorage, SPANISH_ONLY)).toBe('es')
    expect(initialLocale(['en-GB'], null, SPANISH_ONLY)).toBe('es')
  })

  it('con dos idiomas, gana la eleccion guardada y despues el navegador', () => {
    expect(initialLocale(['en-GB'], localStorage, BILINGUAL)).toBe('en')
    localStorage.setItem('kronoqr.kiosk.locale', 'es')
    expect(initialLocale(['en-GB'], localStorage, BILINGUAL)).toBe('es')
  })

  it('sin politica explicita lee la de la copia guardada, y sin copia es solo espanol', () => {
    expect(initialLocale(['en-GB'])).toBe('es')
    localStorage.setItem(BRANDING_KEY, JSON.stringify(RAW_BILINGUAL))
    expect(initialLocale(['en-GB'])).toBe('en')
  })
})

describe('aviso de privacidad (RF-KI-09, RL-09)', () => {
  const notice = {
    controllerName: 'Hotel Ejemplo S.L.',
    policyUrl: 'https://ejemplo.test/privacidad',
  }

  it('esta SIEMPRE en pantalla, no detras de un boton', () => {
    const wrapper = mount(PrivacyNoticePanel, {
      props: { notice },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.get('[data-testid="privacy-notice"]').isVisible()).toBe(true)
  })

  it('lleva los elementos del articulo 13 en capa 1', () => {
    const wrapper = mount(PrivacyNoticePanel, {
      props: { notice },
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
      props: { notice },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.text()).toContain('no trata datos biométricos')
  })

  it('sigue apareciendo aunque falte la configuracion del cliente', () => {
    const wrapper = mount(PrivacyNoticePanel, {
      props: { notice: { controllerName: null, policyUrl: null } },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.get('[data-testid="privacy-notice"]').isVisible()).toBe(true)
    expect(wrapper.text()).toContain('la empresa titular de este centro')
  })

  it('se traduce al ingles', () => {
    const wrapper = mount(PrivacyNoticePanel, {
      props: { notice },
      global: { plugins: [createAppI18n('en')] },
    })

    expect(wrapper.text()).toContain('Data protection notice')
    expect(wrapper.text()).toContain('Legal basis')
  })

  it('mantiene el objetivo tactil de 48 px en los controles aunque el texto sea pequeno', () => {
    const wrapper = mount(PrivacyNoticePanel, {
      props: { notice },
      global: { plugins: [createAppI18n('es')] },
    })

    const button = wrapper.find('button')
    expect(button.classes()).toContain('kiosk-touch')
  })
})
