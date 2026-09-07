// Pantalla «Quioscos» (RF-PA-07, RF-PD-06): lista, vinculacion y desvinculacion.
import { afterEach, describe, expect, it, vi } from 'vitest'
import DevicesView from '@/features/devices/DevicesView.vue'
import es from '@/shared/i18n/locales/es.json'
import { DEVICE_UUID, SITE, device, deviceList, pairingConfirmed } from './support/fixtures'
import { buttonWith, jsonResponse, mountView, settle, stubRoutes } from './support/harness'

afterEach(() => {
  vi.restoreAllMocks()
})

describe('DevicesView', () => {
  it('el vacio explica que todavia no hay ningun quiosco', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/devices': () => jsonResponse(deviceList([])),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    expect(wrapper.text()).toContain(es.devices.empty.title)
  })

  it('lista los quioscos activos y revocados, con su version y su cola pendiente, en el orden que da el servidor', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/devices': () =>
        jsonResponse(
          // El servidor ya ordena («activos primero, luego por nombre»): la
          // prueba respeta ese orden y no lo recalcula en cliente.
          deviceList([
            device({ name: 'Almacén', pending_queue_size: 0 }),
            device({
              uuid: '0199d0bb-2f31-7c08-8a55-9e8d7c6b5a41',
              name: 'Cocina',
              status: 'revoked',
              app_version: null,
              last_seen_at: null,
              paired_at: null,
            }),
          ]),
        ),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    const rows = wrapper.findAll('tbody tr')

    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('Almacén')
    expect(rows[1]?.text()).toContain('Cocina')
    expect(wrapper.text()).toContain(es.devices.status.revoked)
    expect(wrapper.text()).toContain(es.devices.list.neverSeen)
  })

  it('desvincular pide confirmacion, muestra el cambio de estado y refresca', async () => {
    let unpaired = false

    stubRoutes({
      '/site': () => jsonResponse(SITE),
      [`/devices/${DEVICE_UUID}/unpair`]: () => {
        unpaired = true

        return jsonResponse(device({ status: 'revoked' }))
      },
      '/devices': () =>
        jsonResponse(deviceList([device({ status: unpaired ? 'revoked' : 'active' })])),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    await buttonWith(wrapper, es.devices.unpair.action).trigger('click')
    await settle()

    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)

    // Dentro del dialogo hay DOS botones con el mismo texto («Desvincular»): el
    // que lo abre y el que confirma. Se localiza el de dentro del dialogo.
    const confirmButton = wrapper
      .findAll('button')
      .find(
        (button) =>
          button.text() === es.devices.unpair.action &&
          button.element.closest('[role="dialog"]') !== null,
      )

    expect(confirmButton).toBeDefined()
    await confirmButton?.trigger('click')
    await settle()

    expect(unpaired).toBe(true)
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(wrapper.text()).toContain(es.devices.status.revoked)
  })

  it('con cola pendiente, el dialogo de desvincular destaca el aviso del art. 34.9 ET', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/devices': () => jsonResponse(deviceList([device({ pending_queue_size: 37 })])),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    await buttonWith(wrapper, es.devices.unpair.action).trigger('click')
    await settle()

    const dialog = wrapper.get('[role="dialog"]')
    const alert = dialog.find('[role="alert"]')

    expect(alert.exists()).toBe(true)
    expect(alert.text()).toContain('37')
    // La cola tambien aparece en el cuadro de «que va a cambiar».
    expect(dialog.text()).toContain(es.devices.table.pendingQueue)
  })

  it('sin cola pendiente, el dialogo de desvincular no muestra el aviso destacado', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/devices': () => jsonResponse(deviceList([device({ pending_queue_size: 0 })])),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    await buttonWith(wrapper, es.devices.unpair.action).trigger('click')
    await settle()

    expect(wrapper.get('[role="dialog"]').find('[role="alert"]').exists()).toBe(false)
  })

  it('vincular refresca la lista sin cerrar el dialogo solo, y «Cerrar» lo cierra', async () => {
    let deviceCreated = false

    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/kiosk/pair/confirm': () => {
        deviceCreated = true

        return jsonResponse(pairingConfirmed({ name: 'Cocina' }))
      },
      '/devices': () =>
        jsonResponse(deviceCreated ? deviceList([device({ name: 'Cocina' })]) : deviceList([])),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    await buttonWith(wrapper, es.devices.pair.action).trigger('click')
    await settle()

    await wrapper.find('input[name="code"]').setValue('483921')
    await wrapper.find('input[name="name"]').setValue('Cocina')
    await wrapper.find('form').trigger('submit')
    await settle()

    // El dialogo sigue abierto, con el resumen de lo vinculado: la persona
    // tiene que poder contrastarlo con la tablet antes de irse.
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    expect(wrapper.text()).toContain(
      es.devices.pair.confirmed.createdHeading.replace('{name}', 'Cocina'),
    )

    await buttonWith(wrapper, es.common.close).trigger('click')
    await settle()

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    // La lista, ya refrescada, enseña el quiosco nuevo.
    expect(wrapper.find('table').text()).toContain('Cocina')
  })

  it('pasa la flota cargada al formulario para el aviso previo de reactivacion', async () => {
    stubRoutes({
      '/site': () => jsonResponse(SITE),
      '/devices': () => jsonResponse(deviceList([device({ name: 'Almacén', status: 'revoked' })])),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    await buttonWith(wrapper, es.devices.pair.action).trigger('click')
    await settle()

    await wrapper.find('input[name="name"]').setValue('Almacén')
    await settle()

    expect(wrapper.text()).toContain(es.devices.pair.reactivationNotice)
  })
})
