// Salud de quioscos: cola de tamaño desconocido, almacenamiento degradado y
// descartes sin avisar (RF-PA-07, RF-KI-04; RN-21, RN-22).
import { afterEach, describe, expect, it, vi } from 'vitest'
import DevicesView from '@/features/devices/DevicesView.vue'
import { queueSizeLabel, queueStorageKey, reasonKey } from '@/features/devices/devicePresentation'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'
import { device, deviceList } from './support/fixtures'
import { buttonWith, jsonResponse, mountView, settle, stubRoutes } from './support/harness'

afterEach(() => {
  vi.restoreAllMocks()
})

describe('presentación de la cola del quiosco', () => {
  it('un tamaño nulo se lee como «desconocido», nunca como 0', () => {
    expect(queueSizeLabel({ pending_queue_size: null }, 'Desconocido')).toBe('Desconocido')
    expect(queueSizeLabel({ pending_queue_size: 0 }, 'Desconocido')).toBe('0')
    expect(queueSizeLabel({ pending_queue_size: 12 }, 'Desconocido')).toBe('12')
  })

  it('solo el almacenamiento no durable lleva aviso', () => {
    expect(queueStorageKey({ queue_storage: 'durable' })).toBeNull()
    expect(queueStorageKey({ queue_storage: 'memory' })).toBe('devices.queue.storage.memory')
    expect(queueStorageKey({ queue_storage: 'unavailable' })).toBe(
      'devices.queue.storage.unavailable',
    )
  })

  it.each(['queue_storage_degraded', 'discards_unreported'] as const)(
    'la razón %s tiene texto y «qué hacer» en español e inglés',
    (reason) => {
      for (const locale of [es, en]) {
        expect(locale.devices.health.reason[reason].length).toBeGreaterThan(10)
        expect(locale.devices.health.whatToDo[reason].length).toBeGreaterThan(10)
      }

      expect(reasonKey(reason)).toBe(`devices.health.reason.${reason}`)
    },
  )
})

describe('DevicesView — cola desconocida y descartes sin avisar', () => {
  it('una cola de tamaño nulo se muestra como «desconocido» con su razón de fallo', async () => {
    stubRoutes({
      '/devices': () =>
        jsonResponse(
          deviceList([
            device({
              pending_queue_size: null,
              queue_storage: 'memory',
              health: {
                verdict: 'failure',
                reason: 'queue_storage_degraded',
                seconds_since_last_seen: 30,
              },
            }),
          ]),
        ),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    const row = wrapper.get('[data-test="device-row"]')

    expect(row.get('[data-test="queue-size"]').text()).toBe(es.devices.queue.unknown)
    expect(row.get('[data-test="queue-storage"]').text()).toBe(es.devices.queue.storage.memory)
    expect(row.text()).toContain(es.devices.health.reason.queue_storage_degraded)
    expect(row.attributes('data-verdict')).toBe('failure')
    expect(wrapper.get('[data-test="what-to-do"]').text()).toBe(
      es.devices.health.whatToDo.queue_storage_degraded,
    )
  })

  it('con descartes sin avisar, enseña el recuento y la razón de aviso', async () => {
    stubRoutes({
      '/devices': () =>
        jsonResponse(
          deviceList([
            device({
              unreported_discards: 3,
              health: {
                verdict: 'warning',
                reason: 'discards_unreported',
                seconds_since_last_seen: 30,
              },
            }),
          ]),
        ),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    const row = wrapper.get('[data-test="device-row"]')

    expect(row.get('[data-test="unreported-discards"]').text()).toContain('3')
    expect(row.text()).toContain(es.devices.health.reason.discards_unreported)
    expect(row.attributes('data-verdict')).toBe('warning')
    expect(row.find('[data-test="queue-storage"]').exists()).toBe(false)
  })

  it('un quiosco sano no enseña avisos de almacenamiento ni de descartes', async () => {
    stubRoutes({ '/devices': () => jsonResponse(deviceList([device()])) })

    const wrapper = await mountView(DevicesView)
    await settle()

    expect(wrapper.find('[data-test="queue-storage"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="unreported-discards"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="queue-size"]').text()).toBe('0')
  })

  it('desvincular un quiosco con cola desconocida avisa igual, sin decir 0', async () => {
    stubRoutes({
      '/devices': () => jsonResponse(deviceList([device({ pending_queue_size: null })])),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    await buttonWith(wrapper, es.devices.unpair.action).trigger('click')
    await settle()

    const dialog = wrapper.get('[role="dialog"]')

    expect(dialog.get('[data-test="unpair-queue-unknown"]').text()).toBe(
      es.devices.unpair.pendingQueueUnknownWarning,
    )
    expect(dialog.text()).toContain(es.devices.queue.unknown)
  })

  it('desvincular un quiosco con descartes sin avisar lo advierte aparte, con el recuento', async () => {
    stubRoutes({
      '/devices': () => jsonResponse(deviceList([device({ unreported_discards: 4 })])),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    await buttonWith(wrapper, es.devices.unpair.action).trigger('click')
    await settle()

    const alert = wrapper.get('[role="dialog"] [data-test="unpair-unreported-discards"]')

    expect(alert.attributes('role')).toBe('alert')
    expect(alert.text()).toContain('4 fichajes descartados')
  })

  it('sin descartes sin avisar, el diálogo de desvincular no lleva ese aviso', async () => {
    stubRoutes({ '/devices': () => jsonResponse(deviceList([device()])) })

    const wrapper = await mountView(DevicesView)
    await settle()

    await buttonWith(wrapper, es.devices.unpair.action).trigger('click')
    await settle()

    expect(wrapper.find('[data-test="unpair-unreported-discards"]').exists()).toBe(false)
  })
})
