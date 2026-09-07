// El formulario de vinculacion (RF-PD-06): normalizacion del codigo, rechazo
// generico, errores de campo, aviso previo de reactivacion y resumen de
// confirmacion (version de la app y hora en que la tablet pidio el codigo).
import { afterEach, describe, expect, it, vi } from 'vitest'
import PairKioskForm from '@/features/devices/PairKioskForm.vue'
import es from '@/shared/i18n/locales/es.json'
import { device, pairingConfirmed } from './support/fixtures'
import { jsonResponse, mountView, problemResponse, settle, stubFetch } from './support/harness'

afterEach(() => {
  vi.restoreAllMocks()
})

describe('PairKioskForm', () => {
  it('acepta el codigo con el espacio de agrupacion y lo normaliza al enviarlo', async () => {
    const spy = stubFetch(() => jsonResponse(pairingConfirmed()))

    const wrapper = await mountView(PairKioskForm)

    await wrapper.find('input[name="code"]').setValue('483 921')
    await wrapper.find('input[name="name"]').setValue('Recepción')
    await wrapper.find('form').trigger('submit')
    await settle()

    const call = spy.mock.calls.find(([url]) => String(url).endsWith('/kiosk/pair/confirm'))

    expect(call).toBeDefined()
    expect(String(call?.[1]?.body ?? '')).toContain('"code":"483921"')
  })

  it('no deja enviar mientras el codigo no tenga seis digitos', async () => {
    const wrapper = await mountView(PairKioskForm)

    await wrapper.find('input[name="code"]').setValue('4839')
    await wrapper.find('input[name="name"]').setValue('Recepción')
    await settle()

    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
  })

  it('un codigo rechazado muestra el aviso generico, sin distinguir la causa', async () => {
    stubFetch(() => problemResponse(422, 'urn:kronoqr:problem:pairing-code-rejected'))

    const wrapper = await mountView(PairKioskForm)

    await wrapper.find('input[name="code"]').setValue('000000')
    await wrapper.find('input[name="name"]').setValue('Recepción')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.find('[role="alert"]').text()).toBe(es.devices.pair.codeRejected)
    // No es el aviso generico de un `422` de validacion, que hablaria de
    // "campos": el contrato no distingue causas para este rechazo.
    expect(wrapper.text()).not.toContain(es.errors.validation.title)
  })

  it('un nombre en uso llega como error de campo, no como rechazo del codigo', async () => {
    stubFetch(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: { name: ['Ya hay un quiosco activo con ese nombre.'] },
      }),
    )

    const wrapper = await mountView(PairKioskForm)

    await wrapper.find('input[name="code"]').setValue('483921')
    await wrapper.find('input[name="name"]').setValue('Cocina')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain('Ya hay un quiosco activo con ese nombre.')
    expect(wrapper.find('[role="alert"]').text()).not.toBe(es.devices.pair.codeRejected)
  })

  it('al vincular con exito, emite «paired» con el resultado completo', async () => {
    stubFetch(() => jsonResponse(pairingConfirmed({ name: 'Cocina', reactivated: true })))

    const wrapper = await mountView(PairKioskForm)

    await wrapper.find('input[name="code"]').setValue('483921')
    await wrapper.find('input[name="name"]').setValue('Cocina')
    await wrapper.find('form').trigger('submit')
    await settle()

    const emitted = wrapper.emitted('paired')

    expect(emitted).toHaveLength(1)
    expect(emitted?.[0]?.[0]).toMatchObject({
      device: { name: 'Cocina', reactivated: true },
      request: { app_version: '1.4.2' },
    })
  })

  it('tras vincular, enseña el resumen con la version y la hora en que la tablet pidio el codigo', async () => {
    stubFetch(() =>
      jsonResponse(
        pairingConfirmed(
          { name: 'Cocina', reactivated: false },
          { app_version: '1.5.0', requested_at: '2026-09-07T09:55:00.000000Z' },
        ),
      ),
    )

    const wrapper = await mountView(PairKioskForm)

    await wrapper.find('input[name="code"]').setValue('483921')
    await wrapper.find('input[name="name"]').setValue('Cocina')
    await wrapper.find('form').trigger('submit')
    await settle()

    // El formulario desaparece: no se puede volver a enviar por encima del
    // resumen sin pulsar antes «Vincular otro».
    expect(wrapper.find('input[name="code"]').exists()).toBe(false)

    expect(wrapper.text()).toContain(
      es.devices.pair.confirmed.createdHeading.replace('{name}', 'Cocina'),
    )
    expect(wrapper.text()).toContain('1.5.0')
    expect(wrapper.text()).toContain(es.devices.pair.confirmed.advice)

    const pairAnother = wrapper
      .findAll('button')
      .find((button) => button.text() === es.devices.pair.pairAnother)

    expect(pairAnother).toBeDefined()
    await pairAnother?.trigger('click')
    await settle()

    expect(wrapper.find('input[name="code"]').exists()).toBe(true)
  })

  it('la version desconocida se enseña con el texto explicito, no vacia', async () => {
    stubFetch(() => jsonResponse(pairingConfirmed({ name: 'Almacén' }, { app_version: null })))

    const wrapper = await mountView(PairKioskForm)

    await wrapper.find('input[name="code"]').setValue('483921')
    await wrapper.find('input[name="name"]').setValue('Almacén')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain(es.devices.pair.confirmed.appVersionUnknown)
  })

  it('avisa antes de enviar si el nombre coincide con un quiosco revocado', async () => {
    const wrapper = await mountView(PairKioskForm, {
      props: { existingDevices: [device({ name: 'Almacén', status: 'revoked' })] },
    })

    await wrapper.find('input[name="name"]').setValue('Almacén')
    await settle()

    expect(wrapper.text()).toContain(es.devices.pair.reactivationNotice)
  })

  it('no avisa de reactivacion si el nombre no coincide con ningun quiosco revocado', async () => {
    const wrapper = await mountView(PairKioskForm, {
      props: { existingDevices: [device({ name: 'Recepción', status: 'active' })] },
    })

    await wrapper.find('input[name="name"]').setValue('Cocina')
    await settle()

    expect(wrapper.text()).not.toContain(es.devices.pair.reactivationNotice)
  })

  it('sin `existingDevices` (paso del asistente) no hay aviso previo de reactivacion', async () => {
    const wrapper = await mountView(PairKioskForm)

    await wrapper.find('input[name="name"]').setValue('Cualquiera')
    await settle()

    expect(wrapper.text()).not.toContain(es.devices.pair.reactivationNotice)
  })
})
