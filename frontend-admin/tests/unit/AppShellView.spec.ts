import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSessionStore } from '@/features/auth/session.store'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'
import { useBrandingStore } from '@/shared/branding/branding.store'
import { announce, announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import AppShellView from '@/shared/ui/AppShellView.vue'
import { chunkLoadFailed, clearChunkLoadFailure } from '@/router/chunkLoadRecovery'
import type { ReportExport } from '@/shared/api/types'
import { trackedExports } from '@/features/reports/useReportReadyNotices'
import { managementUser } from './support/fixtures'
import {
  createTestPinia,
  createTestRouter,
  jsonResponse,
  mountView,
  settle,
  stubFetch,
} from './support/harness'
import { flushPromises } from '@vue/test-utils'

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

  it('solo ofrece «Cuentas» a quien lleva accounts:* (RF-ID-10)', async () => {
    const admin = await mountShell(['accounts:*', 'employees:*'])
    const hr = await mountShell(['employees:*', 'reports:*'])

    expect(admin.text()).toContain(es.app.nav.accounts)
    expect(hr.text()).not.toContain(es.app.nav.accounts)
    // Sin desmontar, su consulta de exportaciones seguiria viva en las pruebas siguientes.
    admin.unmount()
    hr.unmount()
  })

  it('el auditor (solo reports:legal) solo alcanza «Inspeccion»; estando en otra pantalla, el enlace sigue para volver (bloque 25)', async () => {
    const wrapper = await mountShell(['reports:legal'])
    const links = wrapper.find('nav').findAll('a')

    expect(links.map((link) => link.text())).toEqual([es.app.nav.legalExport])
  })

  it('con un unico enlace y ya en esa pantalla, no hay menu lateral (V21-3-UX-3)', async () => {
    const pinia = createTestPinia()
    const session = useSessionStore(pinia)

    session.user = managementUser({ roles: ['auditor'], abilities: ['reports:legal'] })
    session.token = 'un-token'
    session.status = 'authenticated'

    const router = createTestRouter()

    await router.push('/reports/legal-export')

    const wrapper = await mountView(AppShellView, { pinia, router })

    expect(wrapper.find('nav').exists()).toBe(false)
    // Lo demas del marco sigue: quien ha entrado, cambiar la contrasena y salir.
    expect(wrapper.text()).toContain(es.app.changePassword)
    expect(wrapper.text()).toContain(es.app.signOut)
  })

  it('con varias secciones el menu sigue, tambien estando en una de ellas', async () => {
    const pinia = createTestPinia()
    const session = useSessionStore(pinia)

    session.user = managementUser({ abilities: ['attendance:read', 'employees:read'] })
    session.token = 'un-token'
    session.status = 'authenticated'

    const router = createTestRouter()

    await router.push('/live')

    const wrapper = await mountView(AppShellView, { pinia, router })

    expect(wrapper.find('nav').exists()).toBe(true)
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

function exportRow(uuid: string, status: ReportExport['status']): ReportExport {
  return { uuid, status } as ReportExport
}

describe('AppShellView — informe listo fuera de Informes (V21-5-PA-1, V5-PA-3, R3-PA-08)', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('avisa en la cabecera de lo que termina mientras se esta en otra pantalla, sin anunciar lo que ya estaba completado', async () => {
    vi.useFakeTimers()
    let polls = 0
    stubFetch((url) => {
      if (!url.includes('/api/v1/reports/exports')) {
        return jsonResponse({}, 404)
      }

      polls += 1

      // Primera lista: dos viejas completadas y una en curso. Segunda: la de curso termina.
      return jsonResponse({
        data: [
          exportRow('viejo-1', 'completed'),
          exportRow('viejo-2', 'completed'),
          exportRow('nuevo', polls === 1 ? 'running' : 'completed'),
        ],
      })
    })

    const wrapper = await mountShell(['reports:*'])
    await flushPromises()

    expect(polls).toBe(1)
    expect(wrapper.find('[data-test="report-ready"]').exists()).toBe(false)
    expect(announcement.value).toBe('')

    await vi.advanceTimersByTimeAsync(10_000)
    await flushPromises()

    expect(polls).toBe(2)
    expect(wrapper.find('[data-test="report-ready"]').text()).toContain(
      es.reportExports.ready.message.split(' | ')[0] ?? '',
    )
    // `/forbidden` es hija del marco en el router real: la prueba monta dos marcos anidados
    // y los dos anuncian, de ahi el espacio duro con que `announce` distingue la repeticion.
    expect(announcement.value.trim()).toBe(es.reportExports.ready.announce.split(' | ')[0])

    // Ya no hay nada en curso: el sondeo se apaga solo.
    await vi.advanceTimersByTimeAsync(60_000)

    expect(polls).toBe(2)

    for (const button of wrapper.findAll('[data-test="report-ready-dismiss"]')) {
      await button.trigger('click')
    }

    expect(wrapper.find('[data-test="report-ready"]').exists()).toBe(false)
  })

  /** Una exportacion que tarda un sondeo en completarse; `nuevo` termina en el segundo. */
  async function mountWithFinishingExport(
    beforeFinish: () => void,
  ): Promise<Awaited<ReturnType<typeof mountShell>>> {
    vi.useFakeTimers()
    let polls = 0

    stubFetch((url) => {
      if (!url.includes('/api/v1/reports/exports')) {
        return jsonResponse({}, 404)
      }

      polls += 1

      return jsonResponse({
        data: [
          exportRow('viejo', 'completed'),
          exportRow('nuevo', polls === 1 ? 'running' : 'completed'),
        ],
      })
    })

    const wrapper = await mountShell(['reports:*'])

    await flushPromises()
    beforeFinish()
    await vi.advanceTimersByTimeAsync(3_000)
    await flushPromises()

    return wrapper
  }

  it('si una pantalla sigue la exportacion, la cabecera no repite el aviso ni el anuncio', async () => {
    const wrapper = await mountWithFinishingExport(() => trackedExports.add('nuevo'))

    expect(wrapper.text()).not.toContain(es.reportExports.ready.open)
    expect(announcement.value).toBe('')

    trackedExports.delete('nuevo')
  })

  it('al desmontarse la pantalla que la seguia, la cabecera si avisa de lo que termina despues', async () => {
    const wrapper = await mountWithFinishingExport(() => {
      trackedExports.add('nuevo')
      // La pantalla se desmonta antes de que termine: retira su registro.
      trackedExports.delete('nuevo')
    })

    expect(wrapper.text()).toContain(es.reportExports.ready.open)
    expect(announcement.value.trim()).toBe(es.reportExports.ready.announce.split(' | ')[0])
  })

  it('quien no puede pedir informes no consulta las exportaciones', async () => {
    const spy = stubFetch(() => jsonResponse({ data: [] }))

    await mountShell(['employees:*'])
    await settle()

    expect(spy).not.toHaveBeenCalled()
  })
})

describe('AppShellView — un trozo que no carga (V21-3-UX-1)', () => {
  afterEach(() => {
    clearChunkLoadFailure()
  })

  it('enseña un aviso con «Recargar» cuando la pantalla pedida no se puede abrir', async () => {
    const wrapper = await mountShell(['employees:*'])

    expect(wrapper.find('[data-test="chunk-load-failed"]').exists()).toBe(false)
    expect(chunkLoadFailed.value).toBe(false)

    // Lo activa `installChunkLoadRecovery` en el segundo fallo; aqui se simula con su estado real.
    const { installChunkLoadRecovery } = await import('@/router/chunkLoadRecovery')
    const { createMemoryHistory, createRouter } = await import('vue-router')
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: { render: () => null } },
        {
          path: '/lazy',
          component: () =>
            Promise.reject(new TypeError('Failed to fetch dynamically imported module: x')),
        },
      ],
    })

    installChunkLoadRecovery(router, {
      storage: { getItem: () => '1', setItem: () => undefined, removeItem: () => undefined },
      reload: vi.fn(),
    })
    await router.push('/')
    await router.push('/lazy').catch(() => undefined)
    await settle(1)

    const notice = wrapper.find('[data-test="chunk-load-failed"]')

    expect(notice.attributes('role')).toBe('alert')
    expect(notice.text()).toContain(es.app.chunkLoadFailed.title)
    expect(wrapper.find('[data-test="chunk-load-reload"]').exists()).toBe(true)

    await wrapper.find('[data-test="chunk-load-dismiss"]').trigger('click')

    expect(wrapper.find('[data-test="chunk-load-failed"]').exists()).toBe(false)
  })
})
