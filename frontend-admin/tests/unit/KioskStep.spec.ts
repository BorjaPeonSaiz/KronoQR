import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import KioskStep from '@/features/onboarding/steps/KioskStep.vue'
import { useSetupStore } from '@/features/onboarding/setup.store'
import { pairingConfirmed, setupStatus, setupSteps } from './support/fixtures'
import {
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubRoutes,
} from './support/harness'

// Paso 7: primer quiosco (RF-PD-03, RF-PD-06). Reutiliza `PairKioskForm`, la
// misma pieza que la pantalla «Quioscos»: aqui solo se comprueba que, al
// vincular, el paso se marca hecho, y que «omitir» sigue funcionando.

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('KioskStep', () => {
  it('ofrece el formulario de vinculacion y el boton de omitir', async () => {
    const wrapper = await mountView(KioskStep, { pinia: createTestPinia() })

    expect(wrapper.find('input[name="code"]').exists()).toBe(true)
    expect(wrapper.find('input[name="name"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="skip"]').exists()).toBe(true)
  })

  it('omitir marca el paso omitido', async () => {
    const pinia = createTestPinia()

    stubRoutes({
      '/setup/steps/kiosk': () =>
        jsonResponse(setupStatus({ steps: setupSteps({ kiosk: { state: 'skipped' } }) })),
    })

    const wrapper = await mountView(KioskStep, { pinia })

    await wrapper.find('[data-test="skip"]').trigger('click')
    await settle()

    expect(useSetupStore(pinia).stepState('kiosk')).toBe('skipped')
  })

  it('vincular enseña el resumen, cambia «omitir» por «continuar», y solo entonces se marca completado', async () => {
    const pinia = createTestPinia()

    stubRoutes({
      '/kiosk/pair/confirm': () => jsonResponse(pairingConfirmed()),
      '/setup/steps/kiosk': () =>
        jsonResponse(setupStatus({ steps: setupSteps({ kiosk: { state: 'completed' } }) })),
    })

    const wrapper = await mountView(KioskStep, { pinia })

    await wrapper.find('input[name="code"]').setValue('483 921')
    await wrapper.find('input[name="name"]').setValue('Recepción')
    await wrapper.find('form').trigger('submit')
    await settle()

    // El paso NO avanza solo: se enseña el resumen (version, hora de la
    // solicitud) para contrastarlo con la tablet, y «omitir» ya no tiene
    // sentido una vez vinculado.
    expect(useSetupStore(pinia).stepState('kiosk')).not.toBe('completed')
    expect(wrapper.find('[data-test="skip"]').exists()).toBe(false)

    await wrapper.find('[data-test="continue"]').trigger('click')
    await settle()

    expect(useSetupStore(pinia).stepState('kiosk')).toBe('completed')
  })

  it('un codigo rechazado no marca el paso ni lo bloquea: se puede seguir omitiendo', async () => {
    const pinia = createTestPinia()

    stubRoutes({
      '/kiosk/pair/confirm': () =>
        problemResponse(422, 'urn:kronoqr:problem:pairing-code-rejected'),
      '/setup/steps/kiosk': () =>
        jsonResponse(setupStatus({ steps: setupSteps({ kiosk: { state: 'skipped' } }) })),
    })

    const wrapper = await mountView(KioskStep, { pinia })

    await wrapper.find('input[name="code"]').setValue('000000')
    await wrapper.find('input[name="name"]').setValue('Recepción')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(useSetupStore(pinia).stepState('kiosk')).not.toBe('completed')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)

    await wrapper.find('[data-test="skip"]').trigger('click')
    await settle()

    expect(useSetupStore(pinia).stepState('kiosk')).toBe('skipped')
  })
})
