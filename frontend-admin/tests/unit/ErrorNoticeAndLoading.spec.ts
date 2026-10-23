// Estados de error y de carga compartidos (V3-UX-01, V3-UX-02, V3-UX-03, R3-UX-03).
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { ApiError } from '@kronoqr/web-kit/http'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
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

describe('LoadingPanel: regiones vivas persistentes y espera larga (V3-UX-02, V3-UX-03)', () => {
  it('el texto va en una region viva que existe vacia y se rellena; el aviso lento es otro nodo', async () => {
    vi.useFakeTimers()
    const wrapper = await mountView(LoadingPanel, { props: { label: 'Cargando…' } })
    const [announced, slow] = wrapper.findAll('[role="status"]')

    await nextTick()

    expect(wrapper.text()).toContain('Cargando…')
    expect(announced?.text()).toBe('Cargando…')
    expect(slow?.text()).toBe('')

    await vi.advanceTimersByTimeAsync(10_001)

    expect(wrapper.find('[data-test="loading-slow"]').text()).toContain(es.common.loadingSlow)
    // No promete plazos que no puede cumplir.
    expect(es.common.loadingSlow).not.toMatch(/minuto/)
  })

  it('al desmontarse no deja el temporizador vivo', async () => {
    vi.useFakeTimers()
    const wrapper = await mountView(LoadingPanel, { props: { label: 'Cargando…' } })

    wrapper.unmount()

    expect(vi.getTimerCount()).toBe(0)
  })
})

describe('Licencia y Perfil de cumplimiento: la carga es un LoadingPanel (R3-UX-03, V3-UX-03)', () => {
  it('Licencia: region viva mientras carga', async () => {
    stubFetch(() => new Promise<Response>(() => undefined))
    const wrapper = await mountView(LicenseView)
    const loading = wrapper.find('[data-test="loading"]')

    await nextTick()

    expect(loading.find('[role="status"]').text()).toBe(es.license.loading)
  })

  it('Perfil de cumplimiento: region viva mientras carga', async () => {
    stubFetch(() => new Promise<Response>(() => undefined))
    const wrapper = await mountView(ComplianceProfileView)
    const loading = wrapper.find('[data-test="loading"]')

    await nextTick()

    expect(loading.find('[role="status"]').text()).toBe(es.compliance.loading)
  })
})
