import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSessionStore } from '@/features/auth/session.store'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'
import { useBrandingStore } from '@/shared/branding/branding.store'
import { announce, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import AppShellView from '@/shared/ui/AppShellView.vue'
import { managementUser } from './support/fixtures'
import {
  createTestPinia,
  createTestRouter,
  jsonResponse,
  mountView,
  settle,
  stubFetch,
} from './support/harness'

beforeEach(() => {
  window.sessionStorage.clear()
  clearAnnouncement()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

async function mountShell(
  abilities: string[],
  locale: 'es' | 'en' = 'es',
  pinia = createTestPinia(),
) {
  const session = useSessionStore(pinia)

  session.user = managementUser({ abilities })
  session.token = 'un-token'
  session.status = 'authenticated'

  // Se monta sobre una ruta hija que no pide datos: aqui se prueba el marco, no
  // la pantalla que haya dentro.
  const router = createTestRouter()

  await router.push('/forbidden')

  return mountView(AppShellView, { pinia, locale, router })
}

describe('AppShellView', () => {
  it('muestra los textos en espanol', async () => {
    const wrapper = await mountShell(['employees:*'])

    expect(wrapper.text()).toContain(es.app.title)
  })

  it('muestra los textos en ingles sin tocar la plantilla', async () => {
    const wrapper = await mountShell(['employees:*'], 'en')

    expect(wrapper.text()).toContain(en.app.title)
  })

  it('solo ofrece las secciones que el token alcanza', async () => {
    const wrapper = await mountShell(['employees:*'])

    expect(wrapper.text()).toContain(es.app.nav.employees)
    expect(wrapper.text()).not.toContain(es.app.nav.credentials)
  })

  it('no ofrece ninguna seccion a quien no tiene ambitos de gestion', async () => {
    const wrapper = await mountShell(['attendance:read'])

    expect(wrapper.text()).not.toContain(es.app.nav.employees)
    expect(wrapper.text()).not.toContain(es.app.nav.credentials)
  })

  it('dice quien ha entrado y con que rol', async () => {
    const wrapper = await mountShell(['employees:*'])

    expect(wrapper.text()).toContain('Direccion RRHH')
    expect(wrapper.text()).toContain(es.app.roles.rrhh)
  })

  it('publica los avisos en una region viva', async () => {
    const wrapper = await mountShell(['employees:*'])

    announce('Entrega registrada')
    await settle(1)

    const live = wrapper.find('[aria-live="polite"]')

    expect(live.exists()).toBe(true)
    expect(live.text()).toContain('Entrega registrada')
  })

  it('ofrece un salto al contenido para quien navega con teclado', async () => {
    const wrapper = await mountShell(['employees:*'])

    expect(wrapper.find('a[href="#main"]').exists()).toBe(true)
    expect(wrapper.find('main#main').exists()).toBe(true)
  })

  it('ofrece «Marca» solo a quien tiene el ambito de configuracion (RF-PD-08)', async () => {
    const conAmbito = await mountShell(['employees:*', 'settings:*'])

    expect(conAmbito.text()).toContain(es.app.nav.branding)

    const sinAmbito = await mountShell(['employees:*'])

    expect(sinAmbito.text()).not.toContain(es.app.nav.branding)
  })
})

describe('AppShellView — marca de la instalacion (RF-PD-08)', () => {
  it('sin logotipo, enseña el nombre de la marca del producto en la cabecera', async () => {
    const wrapper = await mountShell(['employees:*'])

    expect(wrapper.find('header img').exists()).toBe(false)
    expect(wrapper.find('header').text()).toContain('KronoQR')
    // El subtitulo «Panel de gestion» se queda, siempre visible.
    expect(wrapper.find('header').text()).toContain(es.app.title)
  })

  it('con logotipo, lo enseña en la cabecera con el nombre como alternativa textual', async () => {
    stubFetch(() =>
      jsonResponse({
        application_name: 'Hotel Marina',
        accent_color: null,
        logo_url: '/api/v1/branding/logo?v=3f9a1c2b7e4d',
        locales: { default: 'es', available: ['es'] },
      }),
    )

    const pinia = createTestPinia()

    await useBrandingStore(pinia).load()

    const wrapper = await mountShell(['employees:*'], 'es', pinia)
    const logo = wrapper.find('header img')

    expect(logo.exists()).toBe(true)
    expect(logo.attributes('alt')).toBe('Hotel Marina')
  })

  it('un nombre de 60 caracteres se trunca visualmente, pero queda completo en el titulo', async () => {
    // El limite del contrato (`Branding.applicationName`, hasta 60
    // caracteres): en una cabecera compartida con la navegacion, sin truncar
    // empujaria las secciones. `BrandMark` (`@kronoqr/web-kit`) lo resuelve;
    // esto solo comprueba que la cabecera lo usa de verdad.
    const longName = 'Complejo Hotelero Costa Dorada Suites & Spa Mediterraneo XLL'

    expect(longName.length).toBe(60)

    stubFetch(() =>
      jsonResponse({
        application_name: longName,
        accent_color: null,
        logo_url: null,
        locales: { default: 'es', available: ['es'] },
      }),
    )

    const pinia = createTestPinia()

    await useBrandingStore(pinia).load()

    const wrapper = await mountShell(['employees:*'], 'es', pinia)
    const name = wrapper.find('header [data-testid="brand-mark"]')

    expect(name.classes()).toContain('truncate')
    expect(name.attributes('title')).toBe(longName)
  })
})
