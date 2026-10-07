// Pantalla de presencia en vivo (RF-PA-01, RF-PA-02, RNF-D-03).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import LivePresenceView from '@/features/live/LivePresenceView.vue'
import { useLivePresenceStore } from '@/features/live/presence.store'
import { useSessionStore } from '@/features/auth/session.store'
import { registerAuthGuard } from '@/router/guards'
import es from '@/shared/i18n/locales/es.json'
import type { LivePresenceBoard } from '@/shared/api/types'
import { managementUser } from './support/fixtures'
import {
  createTestPinia,
  createTestRouter,
  jsonResponse,
  mountView,
  settle,
  stubFetch,
} from './support/harness'

const BOARD: LivePresenceBoard = {
  data: [
    {
      employee_uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
      full_name: 'Youssef Amrani',
      department: { id: 3, name: 'Cocina' },
      status: 'present',
      shift_entry_uuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
      clocked_in_at: '2026-03-14T05:00:00.000000Z',
      origin: 'qr_kiosk',
      device: { uuid: '0199f0d3-3c71-7e52-9a13-6f7a8b9c0d12', name: 'Entrada de personal' },
    },
  ],
  meta: {
    generated_at: '2026-03-14T09:12:00.000000Z',
    time_zone: 'Europe/Madrid',
    present_count: 1,
    absent_count: 4,
    total: 5,
    realtime: {
      enabled: false,
      key: null,
      path: '/app',
      auth_endpoint: '/api/v1/broadcasting/auth',
      event: 'presence.updated',
      channels: ['presence.all'],
      poll_interval_seconds: 15,
      unavailable_reason: null,
      unavailable_since: null,
    },
  },
}

describe('LivePresenceView', () => {
  let requests: string[]

  beforeEach(() => {
    requests = []
    stubFetch((url) => {
      requests.push(url)

      if (url.includes('/api/v1/attendance/live')) {
        return jsonResponse(BOARD)
      }

      if (url.includes('/api/v1/departments')) {
        return jsonResponse({ data: [{ id: 3, name: 'Cocina' }] })
      }

      return jsonResponse({}, 404)
    })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('enseña quien esta dentro, con la hora del centro y el tiempo transcurrido segun el servidor', async () => {
    const wrapper = await mountView(LivePresenceView)
    await settle()

    expect(wrapper.text()).toContain(es.live.title)
    expect(wrapper.find('[data-test="present-count"]').text()).toBe('1')
    expect(wrapper.find('[data-test="absent-count"]').text()).toBe('4')

    const row = wrapper.find('[data-test="presence-entry"]')
    expect(row.text()).toContain('Youssef Amrani')
    expect(row.text()).toContain('Cocina')
    // 05:00 UTC son las 06:00 en Europe/Madrid en marzo (CET), sea cual sea la zona del navegador.
    expect(row.find('[data-test="entry-since"]').text()).toBe('06:00')
    // 09:12 del servidor menos 06:00 de entrada: 4 h 12 min, aunque el reloj local diga otra cosa.
    expect(row.find('[data-test="entry-elapsed"]').text()).toBe('4 h 12 min')
    expect(row.text()).toContain(es.live.origin.qr_kiosk)
    expect(row.text()).toContain('Entrada de personal')

    wrapper.unmount()
  })

  it('con el tiempo real desactivado lo dice en un aviso visible y con el intervalo de sondeo', async () => {
    const wrapper = await mountView(LivePresenceView)
    await settle()

    const transport = wrapper.find('[data-test="transport"]')
    expect(transport.attributes('role')).toBe('status')
    expect(transport.attributes('data-degraded')).toBe('true')
    expect(transport.text()).toBe(es.live.transport.disabled.replace('{seconds}', '15'))

    wrapper.unmount()
  })

  it('manda los filtros al servidor en la peticion, no los aplica en el cliente', async () => {
    const wrapper = await mountView(LivePresenceView)
    await settle()

    await wrapper.find('#live-status-filter').setValue('absent')
    await settle()

    expect(requests.at(-1)).toContain('status=absent')

    await wrapper.find('#live-department-filter').setValue('3')
    await settle()

    expect(requests.at(-1)).toContain('department_id=3')

    wrapper.unmount()
  })

  it('un responsable de departamento, que no tiene plantilla, entra directamente en la presencia', async () => {
    const pinia = createTestPinia()
    const session = useSessionStore(pinia)
    session.token = 'token'
    session.status = 'authenticated'
    session.user = managementUser({
      roles: ['responsable_departamento'],
      abilities: ['attendance:read', 'attendance:correct', 'incidents:*', 'employees:read'],
    })
    const router = createTestRouter()
    registerAuthGuard(router)

    await router.push('/')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('live')
  })

  function signInAs(role: string, abilities: string[]): ReturnType<typeof createTestPinia> {
    const pinia = createTestPinia()
    const session = useSessionStore(pinia)
    session.token = 'token'
    session.status = 'authenticated'
    session.user = managementUser({ roles: [role as never], abilities })

    return pinia
  }

  it('cada fila enlaza al detalle de jornada de esa persona en el dia de su entrada, en la zona del centro', async () => {
    const wrapper = await mountView(LivePresenceView, {
      pinia: signInAs('rrhh', ['attendance:read', 'employees:*']),
    })
    await settle()

    const link = wrapper.find('[data-test="entry-workdays-link"]')

    expect(link.attributes('href')).toBe(
      '/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/workdays?from=2026-03-14&to=2026-03-14',
    )
    expect(link.attributes('aria-label')).toBe(
      es.live.table.openWorkDay.replace('{name}', 'Youssef Amrani'),
    )

    wrapper.unmount()
  })

  it.each([
    ['admin', true],
    ['rrhh', true],
    ['responsable_departamento', true],
    ['auditor', false],
  ])('%s: el enlace a la jornada se muestra = %s', async (role, shown) => {
    const wrapper = await mountView(LivePresenceView, {
      pinia: signInAs(role, ['attendance:read']),
    })
    await settle()

    expect(wrapper.find('[data-test="entry-workdays-link"]').exists()).toBe(shown)
    expect(wrapper.find('[data-test="entry-name"]').text()).toBe('Youssef Amrani')

    wrapper.unmount()
  })

  it('un responsable de departamento llega a la ruta del detalle de jornada', async () => {
    signInAs('responsable_departamento', [
      'attendance:read',
      'attendance:correct',
      'employees:read',
    ])
    const router = createTestRouter()
    registerAuthGuard(router)

    await router.push(
      '/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/workdays?from=2026-03-14&to=2026-03-14',
    )
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('employee-workdays')
  })

  describe('avisos con datos previos (R2-PA-01, R3-PA-01, R3-PA-02)', () => {
    const LATER: LivePresenceBoard = {
      ...BOARD,
      meta: { ...BOARD.meta, generated_at: '2026-03-14T09:20:00.000000Z' },
    }
    let failing = false
    let board: LivePresenceBoard = BOARD

    beforeEach(() => {
      failing = false
      board = BOARD
      stubFetch((url) => {
        if (url.includes('/api/v1/attendance/live')) {
          return failing ? jsonResponse({}, 503) : jsonResponse(board)
        }

        return jsonResponse({ data: [{ id: 3, name: 'Cocina' }] })
      })
    })

    it('un sondeo fallido avisa sin bloquear, conserva la marca del ultimo dato bueno y se retira al recuperarse', async () => {
      const wrapper = await mountView(LivePresenceView)
      await settle()

      expect(wrapper.find('[data-test="refresh-notice"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="generated-at"]').text()).toContain('10:12')

      failing = true
      await useLivePresenceStore().load()
      await settle()

      const notice = wrapper.find('[data-test="refresh-notice"]')

      expect(notice.attributes('data-kind')).toBe('poll')
      expect(notice.attributes('role')).toBe('status')
      expect(notice.text()).toBe(
        es.live.notice.poll
          .replace('{moment}', '14/3/26, 10:12')
          .replace('{zone}', 'Europe/Madrid'),
      )
      expect(notice.attributes('aria-live')).toBe('polite')
      expect(notice.text()).toContain('10:12')
      expect(wrapper.find('[data-test="presence-entry"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="generated-at"]').text()).toContain('10:12')

      failing = false
      board = LATER
      await useLivePresenceStore().load()
      await settle()

      expect(wrapper.find('[data-test="refresh-notice"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="generated-at"]').text()).toContain('10:20')

      wrapper.unmount()
    })

    it('un cambio de filtro que falla avisa de que la lista puede no corresponder al filtro', async () => {
      const wrapper = await mountView(LivePresenceView)
      await settle()

      failing = true
      await wrapper.find('#live-status-filter').setValue('absent')
      await settle()

      expect(wrapper.find('[data-test="refresh-notice"]').attributes('data-kind')).toBe('filter')
      expect(wrapper.find('[data-test="refresh-notice"]').attributes('role')).toBe('status')
      expect(wrapper.find('[data-test="refresh-notice"]').text()).toBe(
        es.live.notice.filter
          .replace('{moment}', '14/3/26, 10:12')
          .replace('{zone}', 'Europe/Madrid'),
      )
      expect(wrapper.find('[data-test="presence-entry"]').exists()).toBe(true)

      wrapper.unmount()
    })

    it('una reconexion fallida del canal avisa y se retira cuando el canal vuelve', async () => {
      const wrapper = await mountView(LivePresenceView)
      await settle()

      const store = useLivePresenceStore()
      store.realtimeFailed = true
      await settle()

      expect(wrapper.find('[data-test="refresh-notice"]').attributes('data-kind')).toBe('realtime')
      expect(wrapper.find('[data-test="refresh-notice"]').attributes('role')).toBe('status')
      expect(wrapper.find('[data-test="refresh-notice"]').text()).toBe(
        es.live.notice.realtime
          .replace('{moment}', '14/3/26, 10:12')
          .replace('{zone}', 'Europe/Madrid'),
      )

      store.realtimeFailed = false
      await settle()

      expect(wrapper.find('[data-test="refresh-notice"]').exists()).toBe(false)

      wrapper.unmount()
    })

    it('en tiempo real un fallo de la foto se reintenta solo y el aviso se retira al recuperarse', async () => {
      vi.useFakeTimers()

      try {
        const store = useLivePresenceStore(createTestPinia())

        await store.load()
        store.transport = 'realtime'

        failing = true
        await store.load()

        expect(store.refreshFailure?.kind).toBe('poll')

        failing = false
        await vi.advanceTimersByTimeAsync(15_000)

        expect(store.refreshFailure).toBeNull()
      } finally {
        vi.useRealTimers()
      }
    })

    it('cerrar la pantalla no deja el canal marcado como caido', async () => {
      const store = useLivePresenceStore(createTestPinia())

      store.realtimeFailed = true
      store.disconnect()

      expect(store.realtimeFailed).toBe(false)
    })
  })
})
