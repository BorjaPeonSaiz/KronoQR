// Aviso de si el logotipo guardado sirve como icono de la tablet (V21-6-UX-3, RF-PD-08).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  logoIconAdvice,
  logoIconIssues,
  MINIMUM_ICON_SIDE,
  readLogoFacts,
  type LogoReaderDeps,
} from '@/features/settings/logoIcon'
import BrandingView from '@/features/settings/BrandingView.vue'
import { useBrandingStore } from '@/shared/branding/branding.store'
import es from '@/shared/i18n/locales/es.json'
import { createTestPinia, jsonResponse, mountView, settle, stubFetch } from './support/harness'
import { installRealThemeTokens } from './support/theme'

const BRANDING_WITH_LOGO = {
  application_name: 'Hotel Marina',
  accent_color: null,
  logo_url: '/api/v1/branding/logo?v=3f9a1c2b7e4d',
  locales: { default: 'es', available: ['es'] },
}

const png = (width: number, height: number, hasTransparency = false) => ({
  mime: 'image/png',
  width,
  height,
  hasTransparency,
})

describe('logoIconIssues', () => {
  it('un PNG cuadrado de 512 px opaco sirve', () => {
    expect(logoIconIssues(png(MINIMUM_ICON_SIDE, MINIMUM_ICON_SIDE))).toEqual([])
  })

  it.each([
    ['un SVG', { mime: 'image/svg+xml', width: 0, height: 0, hasTransparency: false }, ['notPng']],
    ['un PNG apaisado', png(1024, 512), ['notSquare']],
    ['un PNG pequeño', png(256, 256), ['tooSmall']],
    ['un PNG apaisado y pequeño', png(300, 200, true), ['notSquare', 'tooSmall']],
  ])('%s', (_name, facts, expected) => {
    expect(logoIconIssues(facts)).toEqual(expected)
  })
})

describe('logoIconAdvice', () => {
  it('la transparencia es una recomendacion, no un motivo para no usarlo de icono', () => {
    expect(logoIconIssues(png(512, 512, true))).toEqual([])
    expect(logoIconAdvice(png(512, 512, true))).toEqual(['transparent'])
    expect(logoIconAdvice(png(512, 512))).toEqual([])
  })
})

describe('readLogoFacts', () => {
  const decode = vi.fn(async () => ({ width: 512, height: 512, hasTransparency: true }))
  const deps = (response: Response): LogoReaderDeps => ({ fetch: async () => response, decode })

  it('un PNG se decodifica y se miden sus pixeles', async () => {
    const facts = await readLogoFacts(
      '/api/v1/branding/logo',
      deps(new Response(new Blob(['x']), { headers: { 'Content-Type': 'image/png' } })),
    )

    expect(facts).toEqual(png(512, 512, true))
  })

  it('un SVG no se decodifica: basta su tipo', async () => {
    decode.mockClear()
    const facts = await readLogoFacts(
      '/api/v1/branding/logo',
      deps(new Response('<svg/>', { headers: { 'Content-Type': 'image/svg+xml; charset=utf-8' } })),
    )

    expect(facts?.mime).toBe('image/svg+xml')
    expect(decode).not.toHaveBeenCalled()
  })

  it('si no se puede medir (error HTTP o de red) no se avisa de nada', async () => {
    expect(await readLogoFacts('/x', deps(new Response('', { status: 404 })))).toBeNull()
    expect(
      await readLogoFacts('/x', {
        fetch: async () => {
          throw new TypeError('Failed to fetch')
        },
        decode,
      }),
    ).toBeNull()
  })
})

describe('BrandingView, aviso del icono', () => {
  let undoTheme: () => void

  beforeEach(() => {
    window.localStorage.clear()
    undoTheme = installRealThemeTokens()
  })

  afterEach(() => {
    undoTheme()
    window.localStorage.clear()
  })

  it('con un logotipo SVG guardado avisa de que la tablet usara el icono del producto', async () => {
    stubFetch((url) =>
      url.includes('/branding/logo')
        ? new Response('<svg/>', { headers: { 'Content-Type': 'image/svg+xml' } })
        : url.endsWith('/api/v1/branding')
          ? jsonResponse(BRANDING_WITH_LOGO)
          : jsonResponse({ data: [], meta: { unknown_keys: [], invalid_keys: [] } }),
    )
    const pinia = createTestPinia()
    const branding = useBrandingStore(pinia)
    await branding.load()

    const wrapper = await mountView(BrandingView, { pinia })
    await settle()

    const check = wrapper.find('[data-test="logo-icon-check"]')
    expect(check.attributes('role')).toBe('note')
    expect(check.find('[data-test="logo-icon-notPng"]').text()).toBe(
      es.branding.logoIcon.issues.notPng,
    )
    expect(check.text()).toContain(es.branding.logoIcon.consequence)
  })

  it('un PNG 512x512 con alfa sirve de icono: solo recomienda, sin decir que se usara el del producto', async () => {
    stubFetch((url) =>
      url.includes('/branding/logo')
        ? new Response(new Blob(['png']), { headers: { 'Content-Type': 'image/png' } })
        : url.endsWith('/api/v1/branding')
          ? jsonResponse(BRANDING_WITH_LOGO)
          : jsonResponse({ data: [], meta: { unknown_keys: [], invalid_keys: [] } }),
    )
    vi.stubGlobal('createImageBitmap', async () => ({
      width: 512,
      height: 512,
      close: () => undefined,
    }))
    const canvas = {
      getContext: () => ({
        drawImage: () => undefined,
        getImageData: () => ({ data: new Uint8ClampedArray([0, 0, 0, 0]) }),
      }),
    } as unknown as HTMLCanvasElement
    const create = document.createElement.bind(document)
    vi.spyOn(document, 'createElement').mockImplementation(
      (tag: string, options?: ElementCreationOptions) =>
        tag === 'canvas' ? canvas : create(tag, options),
    )
    const pinia = createTestPinia()
    await useBrandingStore(pinia).load()

    const wrapper = await mountView(BrandingView, { pinia })
    await settle()

    expect(wrapper.find('[data-test="logo-icon-check"]').text()).toBe(es.branding.logoIcon.ok)
    const advice = wrapper.find('[data-test="logo-icon-advice"]')
    expect(advice.text()).toContain(es.branding.logoIcon.adviceHeading)
    expect(advice.text()).toContain(es.branding.logoIcon.advice.transparent)
    expect(wrapper.text()).not.toContain(es.branding.logoIcon.consequence)

    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it('sin logotipo guardado no dice nada', async () => {
    stubFetch(() => jsonResponse({ data: [], meta: { unknown_keys: [], invalid_keys: [] } }))

    const wrapper = await mountView(BrandingView)
    await settle()

    expect(wrapper.find('[data-test="logo-icon-check"]').exists()).toBe(false)
  })
})
