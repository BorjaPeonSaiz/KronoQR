import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSessionStore } from '@/features/auth/session.store'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'
import { announce, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import AppShellView from '@/shared/ui/AppShellView.vue'
import { managementUser } from './support/fixtures'
import { createTestPinia, createTestRouter, mountView, settle } from './support/harness'

beforeEach(() => {
  window.sessionStorage.clear()
  clearAnnouncement()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

async function mountShell(abilities: string[], locale: 'es' | 'en' = 'es') {
  const pinia = createTestPinia()
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
})
