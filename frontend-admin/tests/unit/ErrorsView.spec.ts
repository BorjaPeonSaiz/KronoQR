// Pantalla del historico de errores (RF-PD-15, tarea 5.12).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ErrorsView from '@/features/errors/ErrorsView.vue'
import { useSessionStore } from '@/features/auth/session.store'
import type { ErrorEvent, ErrorEventCollection } from '@/shared/api/types'
import es from '@/shared/i18n/locales/es.json'
import { clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { managementUser } from './support/fixtures'
import { createTestPinia, jsonResponse, mountView, settle, stubRoutes } from './support/harness'

function errorEvent(overrides: Partial<ErrorEvent> = {}): ErrorEvent {
  return {
    id: 87,
    level: 'critical',
    source: 'kiosk',
    module: null,
    code: 'kiosk.camera.stream_lost',
    message: 'La camara ha dejado de responder',
    exception_class: null,
    file: null,
    line: null,
    // `reason` es una de las dieciseis claves reales de
    // `ErrorContextAllowlist` (backend), el mismo patron que usa el quiosco
    // de verdad para `kiosk.camera.*` (`useCamera.ts`).
    context: { reason: 'stream_ended' },
    trace_id: '4bf92f3577b34da6a3ce929d0e0e4736',
    device_id: '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81',
    employee_uuid: null,
    app_version: '1.4.2',
    occurrences: 12,
    first_seen_at: '2026-09-01T00:00:00.000000Z',
    last_seen_at: '2026-09-09T07:30:00.000000Z',
    resolved_at: null,
    resolved_by: null,
    ...overrides,
  }
}

function errorEventCollection(
  data: ErrorEvent[] = [errorEvent()],
  overrides: Partial<ErrorEventCollection['meta']> = {},
): ErrorEventCollection {
  return {
    data,
    meta: {
      page: 1,
      per_page: 25,
      total: data.length,
      total_pages: 1,
      open_errors: 0,
      open_critical: data.filter((row) => row.level === 'critical' && row.resolved_at === null)
        .length,
      time_zone: 'Europe/Madrid',
      generated_at: '2026-09-09T08:00:00.000000Z',
      ...overrides,
    },
  }
}

async function mountErrorsView(
  abilities: string[] = ['*'],
): Promise<Awaited<ReturnType<typeof mountView>>> {
  const pinia = createTestPinia()
  const session = useSessionStore(pinia)

  session.token = 'token'
  session.status = 'authenticated'
  session.user = managementUser({ abilities })

  const wrapper = await mountView(ErrorsView, { pinia })

  await settle()

  return wrapper
}

beforeEach(() => {
  clearAnnouncement()
})

afterEach(() => {
  // `stubFetch` (via `stubRoutes`) se desmonta con cada test; `useRealTimers`
  // es barato aunque el test no haya usado reloj falso, y evita que uno que
  // fije `Date` se cuele en el siguiente (mismo criterio que `errors.store.spec.ts`).
  vi.useRealTimers()
})

describe('pantalla del historico de errores', () => {
  it('enseña la cabecera con los recuentos abiertos y la fila del error', async () => {
    stubRoutes({
      '/diagnostics/errors': () => jsonResponse(errorEventCollection()),
    })

    const wrapper = await mountErrorsView()

    expect(wrapper.find('h1').text()).toBe(es.errorEvents.heading)
    expect(wrapper.find('[data-test="open-critical"]').text()).toContain('1')
    expect(wrapper.find('[data-test="error-row"]').text()).toContain('La camara ha dejado')
  })

  it('sin ningun error pendiente, enseña el vacio explicado', async () => {
    stubRoutes({
      '/diagnostics/errors': () => jsonResponse(errorEventCollection([])),
    })

    const wrapper = await mountErrorsView()

    expect(wrapper.text()).toContain(es.errorEvents.empty.open.title)
  })

  it('cambiar el filtro de nivel vuelve a pedir la pagina con ese filtro', async () => {
    const urls: string[] = []
    stubRoutes({
      '/diagnostics/errors': (url) => {
        urls.push(url)

        return jsonResponse(errorEventCollection())
      },
    })

    const wrapper = await mountErrorsView()

    await wrapper.find('#errors-level-filter').setValue('critical')
    await settle()

    expect(urls.at(-1)).toContain('level=critical')
  })

  it('expandir una fila enseña el detalle y el bloque «que hacer»', async () => {
    stubRoutes({
      '/diagnostics/errors': () => jsonResponse(errorEventCollection()),
    })

    const wrapper = await mountErrorsView()

    expect(wrapper.find('[data-test="what-to-do"]').exists()).toBe(false)

    await wrapper.find('[data-test="toggle-87"]').trigger('click')
    await settle()

    const detail = wrapper.find('[data-test="what-to-do"]')

    expect(detail.exists()).toBe(true)
    expect(detail.text()).toContain(es.errorEvents.whatToDo.kiosk.critical)
    expect(wrapper.find('[data-test="detail-location"]').text()).toBe(
      es.errorEvents.detail.notApplicable,
    )
  })

  it('un administrador ve el boton de resolver y, al confirmar, la fila desaparece de pendientes', async () => {
    let resolved = false
    stubRoutes({
      '/diagnostics/errors': () =>
        jsonResponse(errorEventCollection(resolved ? [] : [errorEvent()])),
      '/diagnostics/errors/87/resolve': () => {
        resolved = true

        return jsonResponse(errorEvent({ resolved_at: '2026-09-09T09:00:00.000000Z' }))
      },
    })

    const wrapper = await mountErrorsView(['*'])

    expect(wrapper.find('[data-test="resolve-87"]').exists()).toBe(true)

    await wrapper.find('[data-test="resolve-87"]').trigger('click')
    await settle()

    const dialog = wrapper.find('[role="dialog"]')
    expect(dialog.exists()).toBe(true)

    const confirmButton = wrapper
      .findAll('button')
      .find(
        (button) =>
          button.text() === es.errorEvents.resolve.action &&
          button.element.closest('[role="dialog"]') !== null,
      )

    await confirmButton?.trigger('click')
    await settle()

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="error-row"]').exists()).toBe(false)
  })

  it('con el reloj del PC atrasado, el filtro de periodo usa el reloj del servidor y no lleva cota superior (hallazgo I4)', async () => {
    // El PC de quien mira el panel cree que es una semana antes de lo que
    // dice el servidor (`meta.generated_at` de la primera respuesta,
    // 2026-09-09). Antes de este arreglo, `periodBounds` se calculaba sobre
    // este reloj local y dejaba fuera cualquier error visto DESPUES de esta
    // fecha atrasada, aunque el servidor ya lo conociera.
    // Solo `Date`: `settle()` depende de un `setTimeout` de verdad, y con los
    // temporizadores tambien falsificados se queda esperando para siempre.
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-09-01T00:00:00.000Z'))

    const urls: string[] = []
    stubRoutes({
      '/diagnostics/errors': (url) => {
        urls.push(url)

        return jsonResponse(errorEventCollection())
      },
    })

    const wrapper = await mountErrorsView()

    await wrapper.find('#errors-level-filter').setValue('critical')
    await settle()

    const lastQuery = new URL(urls.at(-1) ?? '', 'http://localhost').searchParams

    // Sin cota superior en absoluto para un preset de «ultimos N dias».
    expect(lastQuery.has('to')).toBe(false)
    // `from` cuenta 7 dias hacia atras desde el reloj del SERVIDOR
    // extrapolado (2026-09-09T08:00:00Z), no desde el reloj local atrasado
    // (que habria dado `2026-08-25T00:00:00.000Z`).
    expect(lastQuery.get('from')).toBe('2026-09-02T08:00:00.000Z')
  })

  it('un acceso de soporte con alcance «diagnostics» no ve el boton de resolver', async () => {
    stubRoutes({
      '/diagnostics/errors': () => jsonResponse(errorEventCollection()),
    })

    // Solo `diagnostics:*`: el alcance por defecto de un acceso de soporte
    // (decision 9, tarea 5.9). Sin `support:*`, `canResolve` es `false`.
    const wrapper = await mountErrorsView(['diagnostics:*'])

    expect(wrapper.find('[data-test="resolve-87"]').exists()).toBe(false)
    // El resto de la pantalla sigue funcionando: sigue pudiendo leer el historico.
    expect(wrapper.find('[data-test="error-row"]').exists()).toBe(true)
  })
})
