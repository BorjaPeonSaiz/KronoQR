// Los estados de «Mi registro»: cargando y cada error (V3-PO-5, V3-PO-8).
//
// Un error no puede dejar a la vista el periodo anterior, ni el resumen ni las
// tarjetas, y cada causa dice que ha pasado y que hacer.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSessionStore } from '@/features/login/session.store'
import MyRecordsView from '@/features/my-records/MyRecordsView.vue'
import es from '@/shared/i18n/locales/es.json'
import { employeeWorkDays, portalEmployee } from './support/fixtures'
import {
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

type Wrapper = Awaited<ReturnType<typeof mountView>>

async function mountWith(handler: (call: number, url: string) => Response | Promise<Response>) {
  const pinia = createTestPinia()
  let call = 0

  useSessionStore(pinia).employee = portalEmployee()
  stubFetch((url) => handler(call++, url))

  const wrapper = await mountView(MyRecordsView, { pinia })

  await settle()

  return wrapper
}

async function applyRange(wrapper: Wrapper, from: string, to: string): Promise<void> {
  const inputs = wrapper.findAll('input[type="date"]')

  await inputs[0]?.setValue(from)
  await inputs[1]?.setValue(to)
  await wrapper.find('form').trigger('submit')
  await settle()
}

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('MyRecordsView, carga', () => {
  it('mientras no hay respuesta enseña que esta cargando, sin resumen ni error', async () => {
    const pinia = createTestPinia()

    useSessionStore(pinia).employee = portalEmployee()
    stubFetch(() => new Promise<Response>(() => {}))

    const wrapper = await mountView(MyRecordsView, { pinia })

    await settle()

    expect(wrapper.text()).toContain(es.myRecords.loading)
    expect(wrapper.find('[data-test="period-summary"]').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('al cambiar de periodo avisa de que se actualiza, sin vaciar la pantalla', async () => {
    let release: (response: Response) => void = () => {}
    const wrapper = await mountWith((call) =>
      call < 1
        ? jsonResponse(employeeWorkDays())
        : new Promise<Response>((resolve) => {
            release = resolve
          }),
    )

    await applyRange(wrapper, '2026-02-01', '2026-02-28')

    expect(wrapper.text()).toContain(es.common.updating)
    expect(wrapper.findAll('[data-test="workday"]')).toHaveLength(1)

    release(jsonResponse(employeeWorkDays()))
  })
})

describe('MyRecordsView, errores con datos previos', () => {
  it('un fallo al cambiar de periodo no deja a la vista el periodo anterior', async () => {
    const wrapper = await mountWith((call) =>
      call < 1 ? jsonResponse(employeeWorkDays()) : problemResponse(503, 'about:blank'),
    )

    expect(wrapper.find('[data-test="resolved-range"]').exists()).toBe(true)

    await applyRange(wrapper, '2026-02-01', '2026-02-28')

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.unavailable.title)
    expect(wrapper.find('[data-test="resolved-range"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="period-summary"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="workday"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('2026-03-14')
  })

  it('volver a consultar tras el error recupera la pantalla', async () => {
    const wrapper = await mountWith((call) =>
      call === 1 ? problemResponse(503, 'about:blank') : jsonResponse(employeeWorkDays()),
    )

    await applyRange(wrapper, '2026-02-01', '2026-02-28')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)

    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test="workday"]')).toHaveLength(1)
  })
})

describe('MyRecordsView, cada causa de error', () => {
  const cases: ReadonlyArray<{
    name: string
    response: () => Response
    title: string
    retryAfter?: number
  }> = [
    {
      name: '403',
      response: () => problemResponse(403, 'about:blank'),
      title: es.errors.forbidden.title,
    },
    {
      name: '429 con Retry-After',
      response: () =>
        new Response(JSON.stringify({ type: 'about:blank', title: 'x', status: 429 }), {
          status: 429,
          headers: { 'Content-Type': 'application/problem+json', 'Retry-After': '30' },
        }),
      title: es.errors.rateLimited.title,
      retryAfter: 30,
    },
    {
      name: '500',
      response: () => problemResponse(500, 'about:blank'),
      title: es.errors.unexpected.title,
    },
    {
      name: '503',
      response: () => problemResponse(503, 'about:blank'),
      title: es.errors.unavailable.title,
    },
  ]

  for (const scenario of cases) {
    it(`${scenario.name}: dice que ha pasado y que hacer`, async () => {
      const wrapper = await mountWith(() => scenario.response())
      const alert = wrapper.find('[role="alert"]')

      expect(alert.text()).toContain(scenario.title)

      if (scenario.retryAfter !== undefined) {
        expect(alert.text()).toContain(`${scenario.retryAfter}`)
      }

      expect(wrapper.find('[data-test="workday"]').exists()).toBe(false)
    })
  }
})
