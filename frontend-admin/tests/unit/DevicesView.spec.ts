// Pantalla «Quioscos» (RF-PA-07, RF-PD-06): lista, salud, vinculacion y
// desvinculacion.
import { afterEach, describe, expect, it, vi } from 'vitest'
import DevicesView from '@/features/devices/DevicesView.vue'
import es from '@/shared/i18n/locales/es.json'
import { DEVICE_UUID, device, deviceList, pairingConfirmed } from './support/fixtures'
import { buttonWith, jsonResponse, mountView, settle, stubRoutes } from './support/harness'

afterEach(() => {
  vi.restoreAllMocks()
})

describe('DevicesView', () => {
  it('el vacio explica que todavia no hay ningun quiosco', async () => {
    stubRoutes({ '/devices': () => jsonResponse(deviceList([])) })

    const wrapper = await mountView(DevicesView)
    await settle()

    expect(wrapper.text()).toContain(es.devices.empty.title)
  })

  it('lista los quioscos activos y revocados, con su version y su cola pendiente, en el orden que da el servidor', async () => {
    stubRoutes({
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
              battery_level: null,
              battery_charging: null,
              health: { verdict: 'revoked', reason: 'revoked', seconds_since_last_seen: null },
            }),
          ]),
        ),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    const rows = wrapper.findAll('[data-test="device-row"]')

    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('Almacén')
    expect(rows[1]?.text()).toContain('Cocina')
    expect(rows[1]?.text()).toContain(es.devices.health.verdict.revoked)
    expect(wrapper.text()).toContain(es.devices.list.neverSeen)
  })

  it('la cabecera de la tabla muestra la zona horaria del centro que trae la respuesta', async () => {
    stubRoutes({
      '/devices': () => jsonResponse(deviceList([device()], { timezone: 'Atlantic/Canary' })),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    expect(wrapper.find('caption').text()).toContain('Atlantic/Canary')
  })

  it('un quiosco en fallo (sin latido) aparece con la fila marcada, el veredicto de fallo y el bloque «que hacer» nombra el runbook', async () => {
    stubRoutes({
      '/devices': () =>
        jsonResponse(
          deviceList([
            device({
              name: 'Cocina',
              last_seen_at: '2026-09-07T09:00:00.000000Z',
              health: { verdict: 'failure', reason: 'silent', seconds_since_last_seen: 900 },
            }),
          ]),
        ),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    const row = wrapper.get('[data-test="device-row"]')

    expect(row.text()).toContain(es.devices.health.verdict.failure)
    expect(row.attributes('data-verdict')).toBe('failure')
    // Resaltado con texto e icono, nunca solo color (WCAG 1.4.1): la clase de
    // tinte de fondo va ADEMAS del texto del veredicto que ya comprueba la
    // linea de arriba.
    expect(row.classes()).toContain('bg-kq-danger-soft')

    const whatToDo = wrapper.get('[data-test="what-to-do"]')

    expect(whatToDo.text()).toContain('quiosco-no-responde.md')
    expect(whatToDo.text()).toContain('kiosk:health')
  })

  it('un quiosco sano (ok) no lleva el bloque «que hacer»: no tiene ninguna accion que ofrecer', async () => {
    stubRoutes({
      '/devices': () =>
        jsonResponse(
          deviceList([
            device({ health: { verdict: 'ok', reason: 'beating', seconds_since_last_seen: 30 } }),
          ]),
        ),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    expect(wrapper.find('[data-test="what-to-do"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain(es.devices.health.whatToDoHeading)
  })

  it('un quiosco con bateria baja y sin cargar avisa en la celda de bateria', async () => {
    stubRoutes({
      '/devices': () =>
        jsonResponse(
          deviceList([
            device({
              battery_level: 8,
              battery_charging: false,
              health: { verdict: 'warning', reason: 'battery_low', seconds_since_last_seen: 30 },
            }),
          ]),
        ),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    const battery = wrapper.get('[data-test="battery"]')

    expect(battery.text()).toContain('8 %')
    expect(battery.text()).toContain(es.devices.battery.notCharging)
    expect(wrapper.get('[data-test="battery-warning"]').text()).toContain(
      es.devices.battery.lowWarning,
    )
  })

  it('sin dato de bateria, la celda dice que no informa', async () => {
    stubRoutes({
      '/devices': () =>
        jsonResponse(deviceList([device({ battery_level: null, battery_charging: null })])),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    const battery = wrapper.get('[data-test="battery"]')

    expect(battery.text()).toContain(es.common.empty)
    expect(battery.text()).toContain(es.devices.battery.unknown)
  })

  it('una cola con fichajes antiguos enseña desde cuando es el mas antiguo', async () => {
    stubRoutes({
      '/devices': () =>
        jsonResponse(
          deviceList(
            [
              device({
                pending_queue_size: 5,
                oldest_pending_at: '2026-09-07T07:00:00.000000Z',
                health: {
                  verdict: 'warning',
                  reason: 'queue_pending',
                  seconds_since_last_seen: 30,
                },
              }),
            ],
            { generated_at: '2026-09-07T10:00:00.000000Z' },
          ),
        ),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    expect(wrapper.get('[data-test="oldest-pending"]').text()).toContain('3 h')
  })

  it('la leyenda enseña los umbrales reales de la instalacion, no unos supuestos', async () => {
    stubRoutes({
      '/devices': () =>
        jsonResponse(
          deviceList([device()], {
            thresholds: {
              fresh_within_seconds: 180,
              silent_after_seconds: 900,
              battery_low_percent: 20,
            },
          }),
        ),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    const legend = wrapper.get('[data-test="thresholds-legend"]')

    expect(legend.text()).toContain('3 min')
    expect(legend.text()).toContain('15 min')
    expect(legend.text()).toContain('20 %')
  })

  it('la leyenda no redondea un umbral afinado a un minuto que no es (90 s no es «2 min»)', async () => {
    stubRoutes({
      '/devices': () =>
        jsonResponse(
          deviceList([device()], {
            thresholds: {
              fresh_within_seconds: 90,
              silent_after_seconds: 20,
              battery_low_percent: 15,
            },
          }),
        ),
    })

    const wrapper = await mountView(DevicesView)
    await settle()

    const legend = wrapper.get('[data-test="thresholds-legend"]').text()

    expect(legend).toContain('1 min 30 s')
    expect(legend).toContain('20 s')
    expect(legend).not.toContain('2 min')
    expect(legend).not.toContain('0 min')
  })

  it('desvincular pide confirmacion, muestra el cambio de estado y refresca', async () => {
    let unpaired = false
    const revokedHealth = {
      verdict: 'revoked' as const,
      reason: 'revoked' as const,
      seconds_since_last_seen: null,
    }

    stubRoutes({
      [`/devices/${DEVICE_UUID}/unpair`]: () => {
        unpaired = true

        return jsonResponse(device({ status: 'revoked', health: revokedHealth }))
      },
      '/devices': () =>
        jsonResponse(
          deviceList([
            unpaired
              ? device({ status: 'revoked', health: revokedHealth })
              : device({ status: 'active' }),
          ]),
        ),
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
    expect(wrapper.text()).toContain(es.devices.health.verdict.revoked)
  })

  it('con cola pendiente, el dialogo de desvincular destaca el aviso del art. 34.9 ET', async () => {
    stubRoutes({
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
