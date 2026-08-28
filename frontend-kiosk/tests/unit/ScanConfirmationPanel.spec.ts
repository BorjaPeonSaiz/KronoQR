import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ScanConfirmationPanel from '@/features/scan/ui/ScanConfirmationPanel.vue'
import type { ScanConfirmation } from '@/features/scan/domain/scanOutcome'
import { createAppI18n } from '@/shared/i18n'
import type { AppLocale } from '@/shared/i18n'

/** 07:02 hora LOCAL, que es lo que se pinta (presentacion, regla dura 3). */
const morning = new Date(2026, 7, 14, 7, 2, 31)
const noonish = new Date(2026, 7, 14, 11, 2, 0)

function render(confirmation: ScanConfirmation, locale: AppLocale = 'es') {
  return mount(ScanConfirmationPanel, {
    props: { confirmation },
    global: { plugins: [createAppI18n(locale)] },
  })
}

describe('pantalla de confirmacion', () => {
  it('reproduce el ejemplo del documento 01: «Buenos dias, Lucia — Entrada 07:02»', () => {
    const wrapper = render({
      kind: 'accepted',
      scanId: 's1',
      occurredAt: morning,
      action: 'clock_in',
      displayName: 'Lucia G.',
      workedMinutes: 0,
      workDate: '2026-08-14',
    })

    expect(wrapper.get('[data-testid="confirmation-headline"]').text()).toBe(
      'Buenos días, Lucia G.',
    )
    expect(wrapper.get('[data-testid="confirmation-detail"]').text()).toBe('Entrada 07:02')
  })

  it('reproduce el segundo: «Hasta luego, Lucia — Salida 11:02 · Hoy: 6 h 0 min»', () => {
    const wrapper = render({
      kind: 'accepted',
      scanId: 's2',
      occurredAt: noonish,
      action: 'clock_out',
      displayName: 'Lucia G.',
      workedMinutes: 360,
      workDate: '2026-08-14',
    })

    expect(wrapper.get('[data-testid="confirmation-headline"]').text()).toBe(
      'Hasta luego, Lucia G.',
    )
    expect(wrapper.get('[data-testid="confirmation-detail"]').text()).toBe('Salida 11:02')
    expect(wrapper.get('[data-testid="confirmation-total"]').text()).toBe('Hoy: 6 h 0 min')
  })

  it('nunca muestra decimales en el acumulado', () => {
    const wrapper = render({
      kind: 'accepted',
      scanId: 's3',
      occurredAt: noonish,
      action: 'clock_out',
      displayName: 'Juan P.',
      workedMinutes: 465,
      workDate: '2026-08-14',
    })

    expect(wrapper.get('[data-testid="confirmation-total"]').text()).toBe('Hoy: 7 h 45 min')
    expect(wrapper.text()).not.toMatch(/\d[.,]\d\s*h/)
  })

  it('un fichaje encolado NO dice «Entrada» ni «Salida»: aun no se sabe', () => {
    const wrapper = render({
      kind: 'pending',
      scanId: 's4',
      occurredAt: morning,
      displayName: 'Lucia G.',
    })

    expect(wrapper.text()).not.toContain('Entrada')
    expect(wrapper.text()).not.toContain('Salida')
    expect(wrapper.get('[data-testid="confirmation-pending-badge"]').text()).toBe(
      'Pendiente de validar',
    )
  })

  it('confirma igual cuando el padron no reconoce la tarjeta (degradacion honesta)', () => {
    const wrapper = render({
      kind: 'pending',
      scanId: 's5',
      occurredAt: morning,
      displayName: null,
    })

    expect(wrapper.get('[data-testid="confirmation-headline"]').text()).toBe('Fichaje registrado')
    expect(wrapper.get('[data-testid="confirmation-detail"]').text()).toContain('07:02')
  })

  it('el PIN «Comprobando…» es honesto: no dice pendiente, ni entrada, ni salida', () => {
    const wrapper = render({ kind: 'verifying', scanId: 's-verifying', occurredAt: morning })
    const panel = wrapper.get('[data-testid="scan-confirmation"]')

    expect(panel.attributes('data-variant')).toBe('verifying')
    expect(wrapper.get('[data-testid="confirmation-headline"]').text()).toBe('Comprobando…')
    expect(wrapper.text()).not.toContain('Entrada')
    expect(wrapper.text()).not.toContain('Salida')
    expect(wrapper.find('[data-testid="confirmation-pending-badge"]').exists()).toBe(false)
    // Tono NEUTRO del sistema visual (doc 06), no uno de los cinco colores de
    // confirmacion: no es un desenlace, es una espera.
    expect(panel.classes()).toContain('bg-kq-kiosk-surface-raised')
    expect(panel.classes()).toContain('text-kq-kiosk-text')
    expect(panel.classes()).not.toContain('bg-kiosk-pending')
  })

  it('avisa del anti-rebote con el texto del documento 01 §11', () => {
    const wrapper = render({
      kind: 'debounced',
      scanId: 's6',
      occurredAt: morning,
      displayName: 'Lucia G.',
      workedMinutes: 240,
      lastAcceptedAt: morning,
    })

    expect(wrapper.get('[data-testid="confirmation-headline"]').text()).toBe(
      'Ya has fichado hace unos segundos',
    )
    expect(wrapper.get('[data-testid="confirmation-total"]').text()).toBe('Hoy: 4 h 0 min')
    // Sin el glifo grande de exclamacion: no es un error, no debe parecerlo.
    expect(wrapper.find('span[aria-hidden="true"]').exists()).toBe(false)
  })

  it('da el MISMO mensaje a un rechazo local y a uno del servidor (regla dura 17)', () => {
    const local = render({ kind: 'unreadable', scanId: 's7', occurredAt: morning })
    const remote = render({ kind: 'rejected', scanId: 's8', occurredAt: morning })

    expect(local.get('[data-testid="confirmation-headline"]').text()).toBe(
      remote.get('[data-testid="confirmation-headline"]').text(),
    )
    expect(local.get('[data-testid="confirmation-detail"]').text()).toBe(
      remote.get('[data-testid="confirmation-detail"]').text(),
    )
    expect(local.get('[data-testid="scan-confirmation"]').attributes('data-variant')).toBe(
      remote.get('[data-testid="scan-confirmation"]').attributes('data-variant'),
    )
  })

  it('no comunica solo por color: cada desenlace lleva variante y simbolo propios', () => {
    const entry = render({
      kind: 'accepted',
      scanId: 's9',
      occurredAt: morning,
      action: 'clock_in',
      displayName: 'Lucia G.',
      workedMinutes: 0,
      workDate: '2026-08-14',
    })
    const exit = render({
      kind: 'accepted',
      scanId: 's10',
      occurredAt: noonish,
      action: 'clock_out',
      displayName: 'Lucia G.',
      workedMinutes: 360,
      workDate: '2026-08-14',
    })

    expect(entry.get('[data-testid="scan-confirmation"]').attributes('data-variant')).toBe('entry')
    expect(exit.get('[data-testid="scan-confirmation"]').attributes('data-variant')).toBe('exit')
    expect(entry.text()).not.toBe(exit.text())
  })

  it('anuncia el resultado a los lectores de pantalla sin esperar turno', () => {
    const wrapper = render({ kind: 'rejected', scanId: 's11', occurredAt: morning })
    const panel = wrapper.get('[data-testid="scan-confirmation"]')

    expect(panel.attributes('role')).toBe('alert')
    expect(panel.attributes('aria-live')).toBe('assertive')
  })

  it('usa tipografia de confirmacion (>= 24 px) en todos los textos que se leen', () => {
    const wrapper = render({
      kind: 'accepted',
      scanId: 's12',
      occurredAt: noonish,
      action: 'clock_out',
      displayName: 'Lucia G.',
      workedMinutes: 360,
      workDate: '2026-08-14',
    })

    for (const testId of ['confirmation-headline', 'confirmation-detail', 'confirmation-total']) {
      expect(wrapper.get(`[data-testid="${testId}"]`).classes().join(' ')).toMatch(/text-confirm-/)
    }
  })

  it('traduce la pantalla entera sin tocar la plantilla', () => {
    const wrapper = render(
      {
        kind: 'accepted',
        scanId: 's13',
        occurredAt: morning,
        action: 'clock_in',
        displayName: 'Lucia G.',
        workedMinutes: 0,
        workDate: '2026-08-14',
      },
      'en',
    )

    expect(wrapper.get('[data-testid="confirmation-headline"]').text()).toBe(
      'Good morning, Lucia G.',
    )
    expect(wrapper.get('[data-testid="confirmation-detail"]').text()).toBe('Clock-in 07:02')
  })
})
