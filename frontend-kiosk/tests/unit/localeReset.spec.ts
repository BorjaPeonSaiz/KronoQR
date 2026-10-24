// @vitest-environment jsdom
// D9 (V7-KI-05): el idioma elegido vale para ese fichaje. Al retirarse la
// confirmacion, la tablet vuelve al idioma de serie de la instalacion.

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, nextTick, shallowRef } from 'vue'
import { clearStoredLocale, createAppI18n, readStoredLocale } from '@/shared/i18n'
import { installationLocale, useLocaleResetAfterScan } from '@/shared/i18n/useLocaleReset'

const BILINGUAL = { default: 'es', available: ['es', 'en'] } as const
const SPANISH_ONLY = { default: 'es', available: ['es'] } as const

function harness(policy: typeof BILINGUAL | typeof SPANISH_ONLY = BILINGUAL) {
  const i18n = createAppI18n('es')
  const confirmation = shallowRef<{ scanId: string } | null>(null)
  const host = defineComponent({
    setup() {
      useLocaleResetAfterScan(confirmation, () => policy)
      return () => null
    },
  })
  mount(host, { global: { plugins: [i18n] } })
  return { i18n, confirmation }
}

beforeEach(() => {
  localStorage.clear()
  vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['fr-FR'])
})

afterEach(() => {
  vi.restoreAllMocks()
  document.documentElement.lang = 'es'
})

describe('el idioma vuelve al de serie tras cada fichaje (D9)', () => {
  it('con una confirmacion en pantalla se respeta el idioma elegido; al retirarse, vuelve', async () => {
    const { i18n, confirmation } = harness()

    i18n.global.locale.value = 'en'
    confirmation.value = { scanId: 'a' }
    await nextTick()
    expect(i18n.global.locale.value).toBe('en')

    confirmation.value = null
    await nextTick()
    expect(i18n.global.locale.value).toBe('es')
    expect(document.documentElement.lang).toBe('es')
  })

  it('dos personas seguidas, sin que la pantalla quede libre, no lo reinician en medio', async () => {
    const { i18n, confirmation } = harness()
    confirmation.value = { scanId: 'a' }
    await nextTick()
    i18n.global.locale.value = 'en'

    confirmation.value = { scanId: 'b' }
    await nextTick()

    expect(i18n.global.locale.value).toBe('en')
  })

  it('sin fichaje no cambia nada: elegir idioma no lo reinicia por si solo', async () => {
    const { i18n } = harness()

    i18n.global.locale.value = 'en'
    await nextTick()

    expect(i18n.global.locale.value).toBe('en')
  })

  it('el idioma de serie es el del navegador si la instalacion lo ofrece, y si no el predeterminado', () => {
    vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['en-GB'])
    expect(installationLocale(BILINGUAL)).toBe('en')
    expect(installationLocale(SPANISH_ONLY)).toBe('es')
  })

  it('retira la eleccion que dejaron las versiones anteriores', () => {
    localStorage.setItem('kronoqr.kiosk.locale', 'en')

    clearStoredLocale()

    expect(readStoredLocale()).toBeNull()
  })
})

describe('una sola fuente para la politica de idiomas', () => {
  it('useLocalePolicy lee la copia de la marca y se actualiza cuando llega una marca nueva', async () => {
    const { useLocalePolicy, useBranding } = await import('@/shared/branding/useBranding')
    localStorage.setItem(
      'kronoqr.kiosk.branding',
      JSON.stringify({
        application_name: 'KronoQR',
        accent_color: null,
        logo_url: null,
        locales: { default: 'es', available: ['es', 'en'] },
        privacy_notice: { controller_name: null, policy_url: null },
      }),
    )

    const policy = useLocalePolicy()
    expect(policy.value.available).toEqual(['es', 'en'])

    // Una marca nueva del servidor, con un solo idioma.
    const next = {
      application_name: 'KronoQR',
      accent_color: null,
      logo_url: null,
      locales: { default: 'es', available: ['es'] },
      privacy_notice: { controller_name: null, policy_url: null },
    }
    useBranding({
      api: { fetchBranding: async () => ({ outcome: 'ok', data: next }) } as never,
    })
    await vi.waitFor(() => expect(policy.value.available).toEqual(['es']))
    // Otra pantalla que la pide despues ve la MISMA politica.
    expect(useLocalePolicy().value.available).toEqual(['es'])
  })
})
