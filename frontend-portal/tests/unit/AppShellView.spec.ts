import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import App from '@/App.vue'
import { useSessionStore } from '@/features/login/session.store'
import es from '@/shared/i18n/locales/es.json'
import en from '@/shared/i18n/locales/en.json'
import { employeeWorkDays, portalEmployee } from './support/fixtures'
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
})

afterEach(() => {
  window.sessionStorage.clear()
})

describe('AppShellView', () => {
  it('muestra el titulo del portal y saluda a quien ha entrado por su nombre', async () => {
    const pinia = createTestPinia()

    useSessionStore(pinia).employee = portalEmployee({ display_name: 'Youssef Amrani' })
    stubFetch(() => jsonResponse(employeeWorkDays()))

    const router = createTestRouter()

    await router.push('/records')

    const wrapper = await mountView(App, { pinia, router })

    await settle()

    expect(wrapper.text()).toContain(es.app.title)
    expect(wrapper.text()).toContain('Youssef Amrani')
  })

  it('se pinta en ingles sin tocar la plantilla', async () => {
    const pinia = createTestPinia()

    useSessionStore(pinia).employee = portalEmployee()
    stubFetch(() => jsonResponse(employeeWorkDays()))

    const router = createTestRouter()

    await router.push('/records')

    const wrapper = await mountView(App, { pinia, router, locale: 'en' })

    await settle()

    expect(wrapper.text()).toContain(en.app.title)
    expect(wrapper.text()).toContain(en.app.nav.myRecords)
  })

  it('ofrece la navegacion a las dos pantallas propias, y ninguna mas', async () => {
    const pinia = createTestPinia()

    useSessionStore(pinia).employee = portalEmployee()
    stubFetch(() => jsonResponse(employeeWorkDays()))

    const router = createTestRouter()

    await router.push('/records')

    const wrapper = await mountView(App, { pinia, router })

    await settle()

    const links = wrapper.findAll('nav a').map((link) => link.text())

    expect(links).toEqual([es.app.nav.myRecords, es.app.nav.myExport])
  })

  it('cerrar sesion olvida la sesion local y vuelve al acceso', async () => {
    const pinia = createTestPinia()
    const session = useSessionStore(pinia)

    session.employee = portalEmployee()
    stubFetch(() => jsonResponse(employeeWorkDays()))

    const router = createTestRouter()

    await router.push('/records')

    const wrapper = await mountView(App, { pinia, router })

    await settle()
    await wrapper.find('button').trigger('click')
    await settle()

    expect(session.isAuthenticated).toBe(false)
    expect(router.currentRoute.value.name).toBe('login')
  })

  it('tiene un enlace para saltar al contenido principal', async () => {
    const pinia = createTestPinia()

    useSessionStore(pinia).employee = portalEmployee()
    stubFetch(() => jsonResponse(employeeWorkDays()))

    const router = createTestRouter()

    await router.push('/records')

    const wrapper = await mountView(App, { pinia, router })

    const skipLink = wrapper.find('a[href="#main"]')

    expect(skipLink.exists()).toBe(true)
    expect(wrapper.find('#main').exists()).toBe(true)
  })
})
