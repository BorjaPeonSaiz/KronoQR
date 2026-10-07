import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import TemporaryPasswordDialog from '@/features/accounts/TemporaryPasswordDialog.vue'
import es from '@/shared/i18n/locales/es.json'
import { temporaryPassword } from './support/fixtures'
import { mountView } from './support/harness'

function props() {
  return {
    password: temporaryPassword(),
    accountName: 'Jefatura de Cocina',
    accountEmail: 'cocina@hotel.example',
    timezone: 'Europe/Madrid',
    reason: 'created' as const,
  }
}

beforeEach(() => {
  window.sessionStorage.clear()
  window.localStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('TemporaryPasswordDialog', () => {
  it('enseña la contraseña, avisa de que es de una sola vez y da la caducidad en la zona del centro', async () => {
    const wrapper = await mountView(TemporaryPasswordDialog, { props: props() })

    expect(wrapper.find('[data-test="password-value"]').text()).toBe('Kd2pQ9vLmN4tZbYc#F1w')
    expect(wrapper.text()).toContain(es.accounts.reveal.onlyOnce)
    expect(wrapper.text()).toContain('10:00')
    expect(wrapper.text()).toMatch(/CEST|UTC\+2|GMT\+2/)
  })

  it('no la escribe en ningun almacenamiento del navegador', async () => {
    await mountView(TemporaryPasswordDialog, { props: props() })

    expect(window.sessionStorage.length).toBe(0)
    expect(window.localStorage.length).toBe(0)
  })

  it('no se cierra con Escape ni pulsando fuera', async () => {
    const wrapper = await mountView(TemporaryPasswordDialog, { props: props() })

    await wrapper.find('[role="dialog"]').trigger('keydown', { key: 'Escape' })
    await wrapper.find('[aria-hidden="true"]').trigger('click')

    expect(wrapper.emitted('acknowledged')).toBeUndefined()
  })

  it('solo se cierra tras confirmar que se ha entregado en mano', async () => {
    const wrapper = await mountView(TemporaryPasswordDialog, { props: props() })
    const close = wrapper.find('[data-test="acknowledge"]')

    expect(close.attributes('disabled')).toBeDefined()

    await wrapper.find('[data-test="handed-over"]').setValue(true)
    await close.trigger('click')

    expect(wrapper.emitted('acknowledged')).toHaveLength(1)
  })

  it('no ofrece copiar al portapapeles (como el PIN)', async () => {
    const wrapper = await mountView(TemporaryPasswordDialog, { props: props() })

    expect(wrapper.find('[data-test="copy-password"]').exists()).toBe(false)
  })
})
