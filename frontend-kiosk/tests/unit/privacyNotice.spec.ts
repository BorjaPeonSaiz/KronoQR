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

    expect(readPrivacyNotice()).toEqual({
      controllerName: null,
      policyUrl: null,
      retentionYears: null,
    })
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

describe('RF-KI-09 / RL-09 — plazo de conservacion y reclamacion (art. 13 RGPD, reglas duras 13 y 14)', () => {
  function mountWithYears(years: number | null | undefined, locale: 'es' | 'en' = 'es') {
    return mount(PrivacyNoticePanel, {
      props: {
        notice: {
          controllerName: null,
          policyUrl: null,
          ...(years === undefined ? {} : { retentionYears: years }),
        },
      },
      global: { plugins: [createAppI18n(locale)] },
    })
  }

  it('interpola el plazo del perfil, no uno fijo', () => {
    expect(mountWithYears(7).text()).toContain(
      'Conservación: 7 años, según la normativa aplicable.',
    )
    expect(mountWithYears(7).text()).not.toContain('4 años')
  })

  it('usa el singular con un solo año, en espanol y en ingles', () => {
    expect(mountWithYears(1).text()).toContain('Conservación: 1 año, según')
    expect(mountWithYears(1, 'en').text()).toContain('Retention: 1 year, as required')
    expect(mountWithYears(5, 'en').text()).toContain('Retention: 5 years, as required')
  })

  it.each([[null], [undefined], [0], [-2], [2.5]])(
    'con %s, redaccion generica sin cifra',
    (years) => {
      const text = mountWithYears(years as number | null | undefined).text()
      expect(text).toContain('Conservación: el plazo que fija la normativa aplicable.')
      expect(text).not.toMatch(/Conservación: \S*\d/)
    },
  )

  it('una copia de la marca anterior a la 2.3.0, sin retention_years, no rompe y va sin cifra', () => {
    cacheBranding({
      privacy_notice: { controller_name: 'Hotel Marina S.L.', policy_url: null },
    })
    const wrapper = mountNotice()

    expect(readPrivacyNotice().retentionYears).toBeNull()
    expect(wrapper.text()).toContain('Hotel Marina S.L.')
    expect(wrapper.text()).toContain('el plazo que fija la normativa aplicable')
  })

  it('una copia con el plazo lo enseña sin red', () => {
    cacheBranding({
      privacy_notice: { controller_name: null, policy_url: null, retention_years: 6 },
    })

    expect(mountNotice().text()).toContain('Conservación: 6 años')
  })

  it('menciona el derecho a reclamar ante la AEPD y no promete sin matizar', () => {
    const es = mountWithYears(4).text()
    expect(es).toContain('reclamar ante la Agencia Española de Protección de Datos')
    expect(es).toContain('obligación legal')
    expect(es).toContain('pueden estar limitados')

    const en = mountWithYears(4, 'en').text()
    expect(en).toContain('complain to the Spanish Data Protection Agency')
    expect(en).toContain('legal obligation')
    expect(en).toContain('may be limited')
  })
})
