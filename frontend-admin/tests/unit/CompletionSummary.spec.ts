import { describe, expect, it } from 'vitest'
import CompletionSummary from '@/features/onboarding/CompletionSummary.vue'
import es from '@/shared/i18n/locales/es.json'
import { setupCompletion } from './support/fixtures'
import { mountView } from './support/harness'

// El resumen final accionable (RF-PD-03): la cifra de tarjetas pendientes por
// delante de todo lo demas (ADR-014).

describe('CompletionSummary', () => {
  it('con tarjetas pendientes, lo dice arriba y en tono de aviso', async () => {
    const completion = setupCompletion({
      summary: {
        employees: 42,
        departments: 5,
        credentials_pending: 42,
        license: 'absent',
        kiosks: 0,
      },
    })

    const wrapper = await mountView(CompletionSummary, { props: { completion } })

    const alert = wrapper.find('[data-test="credentials-alert"]')

    expect(alert.text()).toContain('42')
    expect(alert.text()).toContain(es.onboarding.completion.credentialsAdvice)
    expect(wrapper.find('[data-test="summary"]').text()).toContain('42')
  })

  it('sin tarjetas pendientes, lo dice en tono tranquilizador y sin el consejo', async () => {
    const completion = setupCompletion({
      summary: {
        employees: 0,
        departments: 0,
        credentials_pending: 0,
        license: 'absent',
        kiosks: 0,
      },
    })

    const wrapper = await mountView(CompletionSummary, { props: { completion } })

    const alert = wrapper.find('[data-test="credentials-alert"]')

    expect(alert.text()).toContain(es.onboarding.completion.credentialsNone)
    expect(alert.text()).not.toContain(es.onboarding.completion.credentialsAdvice)
  })
})
