// @vitest-environment jsdom
// R7-KI-01, K2, V7-KI-03: el texto legal y la linea de marca no bajan de 18 px
// (text-lg), y el aviso de desfase se lee de pie (24 px). V7-KI-04: el diagnostico
// habla el idioma de la pantalla, en todos los estados de la camara.

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { CameraState } from '@/features/scan/composables/useCamera'
import ScanConfirmationPanel from '@/features/scan/ui/ScanConfirmationPanel.vue'
import { createAppI18n, messages } from '@/shared/i18n'
import PrivacyNoticePanel from '@/shared/ui/PrivacyNoticePanel.vue'

const SMALL_TEXT = /(^|\s)text-(xs|sm|base)(\s|$)/

describe('el aviso de privacidad a 18 px como minimo (RF-KI-09, K2, R7-KI-01)', () => {
  it('ningun texto del aviso usa un tamano por debajo de text-lg', () => {
    const wrapper = mount(PrivacyNoticePanel, {
      props: {
        notice: { controllerName: 'Hotel Ejemplo S.L.', policyUrl: 'https://ejemplo.test/p' },
      },
      global: { plugins: [createAppI18n('es')] },
    })

    const texts = wrapper.findAll(
      '[data-testid="privacy-notice"] p, [data-testid="privacy-show-qr"]',
    )
    expect(texts.length).toBeGreaterThanOrEqual(3)
    for (const element of texts) {
      expect(element.classes().join(' ')).not.toMatch(SMALL_TEXT)
      expect(element.classes()).toContain('text-lg')
    }
  })

  it('sin politica publicada, la frase de «disponible en recepcion» tambien', () => {
    const wrapper = mount(PrivacyNoticePanel, {
      props: { notice: { controllerName: null, policyUrl: null } },
      global: { plugins: [createAppI18n('es')] },
    })

    for (const element of wrapper.findAll('[data-testid="privacy-notice"] p')) {
      expect(element.classes()).toContain('text-lg')
    }
  })
})

describe('la linea de marca de la confirmacion a 18 px como minimo (V7-KI-03)', () => {
  it('es text-lg', () => {
    const wrapper = mount(ScanConfirmationPanel, {
      props: {
        confirmation: {
          kind: 'accepted',
          scanId: 's1',
          occurredAt: new Date('2026-08-14T05:02:00.000Z'),
          action: 'clock_in',
          displayName: 'Lucia G.',
          workedMinutes: 0,
          workDate: '2026-08-14',
        },
        branding: {
          applicationName: 'Hotel Ejemplo',
          logoUrl: null,
          accentColor: null,
          locales: { default: 'es', available: ['es'] },
          privacyNotice: { controllerName: null, policyUrl: null },
        },
      },
      global: { plugins: [createAppI18n('es')] },
    })

    const brand = wrapper.get('p[aria-hidden="true"]')
    expect(brand.classes()).toContain('text-lg')
  })
})

describe('el diagnostico no enseña valores tecnicos sin traducir (V7-KI-04)', () => {
  const CAMERA_STATES = [
    'idle',
    'starting',
    'running',
    'denied',
    'unavailable',
  ] as const satisfies readonly CameraState[]

  it.each(['es', 'en'] as const)(
    '%s: un texto distinto del valor crudo para cada estado',
    (locale) => {
      const values = messages[locale].diagnostics.values.cameraState
      for (const state of CAMERA_STATES) {
        expect(values[state]).toBeTypeOf('string')
        expect(values[state]).not.toBe(state)
      }
    },
  )

  it('la clave muerta `scan.srAnnouncement` ya no existe en ningun idioma', () => {
    expect('srAnnouncement' in messages.es.scan).toBe(false)
    expect('srAnnouncement' in messages.en.scan).toBe(false)
  })
})
