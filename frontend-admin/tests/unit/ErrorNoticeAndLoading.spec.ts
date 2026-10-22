// Estados de error y de carga compartidos (V3-UX-01, V3-UX-02, V3-UX-03, R3-UX-03).
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { ApiError } from '@kronoqr/web-kit/http'
import { afterEach, describe, expect, it, vi } from 'vitest'
import ComplianceProfileView from '@/features/settings/ComplianceProfileView.vue'
import LicenseView from '@/features/settings/LicenseView.vue'
import es from '@/shared/i18n/locales/es.json'
import { mountView, stubFetch } from './support/harness'

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('ErrorNotice: «Reintentar»', () => {
  const error = new ApiError({ kind: 'network', status: 0 })

  it('sin @retry no hay boton (un error de formulario no se reintenta solo)', async () => {
    const wrapper = await mountView(ErrorNotice, { props: { error } })

    expect(wrapper.find('[data-test="error-retry"]').exists()).toBe(false)
  })

  it('con @retry pinta el boton dentro de la alerta y lo dispara', async () => {
    const onRetry = vi.fn()
    const wrapper = await mountView(ErrorNotice, { props: { error, onRetry } })
    const button = wrapper.find('[role="alert"] button')

    expect(button.text()).toBe(es.errors.retry)
    expect(button.attributes('type')).toBe('button')

    await button.trigger('click')

    expect(onRetry).toHaveBeenCalledTimes(1)
  })
})

describe('LoadingPanel: espera larga (V3-UX-02)', () => {
  it('pasado el umbral dice que tarda mas de lo normal, en la misma region viva', async () => {
    vi.useFakeTimers()
    const wrapper = await mountView(LoadingPanel, { props: { label: 'Cargando…' } })

    expect(wrapper.attributes('role')).toBe('status')
    expect(wrapper.text()).toBe('Cargando…')

    await vi.advanceTimersByTimeAsync(10_001)

    expect(wrapper.text()).toContain('Cargando…')
    expect(wrapper.text()).toContain(es.common.loadingSlow)
  })

  it('al desmontarse no deja el temporizador vivo', async () => {
    vi.useFakeTimers()
    const wrapper = await mountView(LoadingPanel, { props: { label: 'Cargando…' } })

    wrapper.unmount()

    expect(vi.getTimerCount()).toBe(0)
  })
})

describe('Licencia y Perfil de cumplimiento: la carga es un LoadingPanel (R3-UX-03, V3-UX-03)', () => {
  it('Licencia: role=status mientras carga, y la tabla de limites tiene nombre (V7-PA-8)', async () => {
    stubFetch(() => new Promise<Response>(() => undefined))
    const wrapper = await mountView(LicenseView)

    const loading = wrapper.find('[data-test="loading"]')

    expect(loading.attributes('role')).toBe('status')
    expect(loading.text()).toContain(es.license.loading)
  })

  it('Perfil de cumplimiento: role=status mientras carga', async () => {
    stubFetch(() => new Promise<Response>(() => undefined))
    const wrapper = await mountView(ComplianceProfileView)
    const loading = wrapper.find('[data-test="loading"]')

    expect(loading.attributes('role')).toBe('status')
    expect(loading.text()).toContain(es.compliance.loading)
  })
})
