// El aviso de privacidad lee el responsable y la politica de la MARCA de la
// instalacion (`GET /api/v1/branding`, `privacy_notice`), que el quiosco ya
// guarda en `localStorage` y por tanto sigue enseñando sin red (RF-KI-09,
// RL-09, art. 13 RGPD). Ya no son variables de entorno de compilacion (regla
// dura 13: vender a un hotel nuevo no puede exigir recompilar).
//
// SIN ENLACE NAVEGABLE (F14 del dictamen de seguridad): la tablet es compartida
// y tocar un aviso legal no puede sacar al empleado del quiosco. La URL va como
// texto y como QR, con el host visible bajo el QR (RL-09 admite «enlace o QR»).

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { readPrivacyNotice } from '@/shared/branding/useBranding'
import { createAppI18n } from '@/shared/i18n'
import PrivacyNoticePanel from '@/shared/ui/PrivacyNoticePanel.vue'

const STORAGE_KEY = 'kronoqr.kiosk.branding'

const BRANDING = {
  application_name: 'Hotel Marina',
  accent_color: null,
  logo_url: null,
  locales: { default: 'es', available: ['es', 'en'] },
}

function cacheBranding(extra: Record<string, unknown>): void {
  localStorage.setItem(STORAGE_KEY, JSON.stringify({ ...BRANDING, ...extra }))
}

function mountNotice(): ReturnType<typeof mount> {
  return mount(PrivacyNoticePanel, {
    props: { notice: readPrivacyNotice() },
    global: { plugins: [createAppI18n('es')] },
  })
}

beforeEach(() => {
  localStorage.clear()
})

describe('RF-KI-09 / RL-09 — aviso de privacidad desde la marca', () => {
  it('sin copia de la marca, enseña el aviso generico y sin direccion', () => {
    const wrapper = mountNotice()

    expect(wrapper.get('[data-testid="privacy-notice"]').isVisible()).toBe(true)
    expect(wrapper.text()).toContain('la empresa titular de este centro')
    expect(wrapper.text()).toContain('Política completa disponible en recepción')
    expect(wrapper.find('[data-testid="privacy-policy-url"]').exists()).toBe(false)
    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('una copia anterior a la 2.2.0, sin `privacy_notice`, es el generico (no rompe la marca)', () => {
    cacheBranding({})

    expect(readPrivacyNotice()).toEqual({ controllerName: null, policyUrl: null })
    const wrapper = mountNotice()
    expect(wrapper.text()).toContain('la empresa titular de este centro')
    expect(wrapper.find('[data-testid="privacy-policy-url"]').exists()).toBe(false)
  })

  it('con responsable y URL: enseña el responsable, la URL como TEXTO y el QR local con su host (F14)', async () => {
    cacheBranding({
      privacy_notice: {
        controller_name: 'Hotel Marina S.L.',
        policy_url: 'https://marina.example/privacidad',
      },
    })
    const wrapper = mountNotice()

    expect(wrapper.text()).toContain('Hotel Marina S.L.')
    expect(wrapper.get('[data-testid="privacy-policy-url"]').text()).toContain(
      'https://marina.example/privacidad',
    )
    // F14: nada navegable. Tocar el aviso no puede sacar de la pantalla.
    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.find('[href]').exists()).toBe(false)

    await wrapper.get('button').trigger('click')
    await vi.waitFor(() => expect(wrapper.find('svg path').exists()).toBe(true))
    // El QR lo pinta el codificador local, y el host se lee bajo el.
    expect(wrapper.get('svg').attributes('role')).toBe('img')
    expect(wrapper.get('[data-testid="privacy-qr-host"]').text()).toContain('marina.example')
    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('una URL de `javascript:` en una copia manipulada no produce direccion, enlace ni QR', () => {
    cacheBranding({
      privacy_notice: { controller_name: 'Hotel Marina S.L.', policy_url: 'javascript:alert(1)' },
    })

    expect(readPrivacyNotice().policyUrl).toBeNull()
    const wrapper = mountNotice()
    expect(wrapper.text()).toContain('Hotel Marina S.L.')
    expect(wrapper.text()).toContain('Política completa disponible en recepción')
    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.html()).not.toContain('javascript:')
  })

  it.each([
    ['http (sin TLS)', 'http://marina.example/privacidad'],
    ['con espacios', 'https://marina.example/a b'],
    ['no ASCII', 'https://marina.example/mariña'],
  ])(
    'F15: una URL %s de una copia manipulada se descarta: texto generico, sin QR',
    (_case, url) => {
      cacheBranding({ privacy_notice: { controller_name: null, policy_url: url } })

      expect(readPrivacyNotice().policyUrl).toBeNull()
      const wrapper = mountNotice()
      expect(wrapper.text()).toContain('Política completa disponible en recepción')
      expect(wrapper.find('button').exists()).toBe(false)
    },
  )

  it('el panel tampoco se fia: un `policyUrl` que no es https no llega a pantalla', () => {
    const wrapper = mount(PrivacyNoticePanel, {
      props: { notice: { controllerName: null, policyUrl: 'javascript:alert(1)' } },
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.html()).not.toContain('javascript:')
  })
})
