// Titulo de la pestaña por pantalla (WCAG 2.4.2, V7-PA-2, R7-PO-03), compartido por el panel y el
// portal. La marca ya no toca `<title>` (`manageTitle: false`): este modulo es su unico dueño y no
// vigila el DOM. Lo que importa es que una marca que falla o llega invalida no pierde el titulo.
import { afterEach, describe, expect, it } from 'vitest'
import { ref } from 'vue'
import { applyBranding, PRODUCT_BRANDING } from '../../src/branding'
import { createBrandingState } from '../../src/brandingState'
import { bindDocumentTitle } from '../../src/documentTitle'

function setup() {
  document.head.innerHTML = '<title>KronoQR</title>'
  const locale = ref('es')
  const name = ref('KronoQR')
  const currentRoute = ref({ meta: { titleKey: 'screen.title' as string | undefined } })
  const binding = bindDocumentTitle({
    router: { currentRoute },
    i18n: { global: { t: (key: string) => `[${locale.value}] ${key}`, locale } },
    applicationName: () => name.value,
  })

  return { binding, locale, currentRoute, name }
}

afterEach(() => {
  document.head.innerHTML = ''
})

describe('bindDocumentTitle', () => {
  it('compone «pantalla – instalacion» y sigue al idioma y a la ruta', () => {
    const { binding, locale, currentRoute } = setup()

    expect(document.title).toBe('[es] screen.title – KronoQR')

    locale.value = 'en'
    expect(document.title).toBe('[en] screen.title – KronoQR')

    currentRoute.value = { meta: { titleKey: undefined } }
    expect(document.title).toBe('KronoQR')

    binding.stop()
  })

  it('sigue al nombre de la instalacion cuando llega la marca', () => {
    const { binding, name } = setup()

    name.value = 'Hotel Marina'

    expect(document.title).toBe('[es] screen.title – Hotel Marina')
    binding.stop()
  })

  it('una marca que falla (red caida) no pierde el titulo de pantalla', async () => {
    const { binding } = setup()
    const state = createBrandingState({
      mode: 'light',
      manageTitle: false,
      storage: null,
      fetchBranding: async () => {
        throw new TypeError('Failed to fetch')
      },
    })

    await state.load()

    expect(document.title).toBe('[es] screen.title – KronoQR')
    binding.stop()
  })

  it('una marca con forma invalida tampoco', async () => {
    const { binding } = setup()
    const state = createBrandingState({
      mode: 'light',
      manageTitle: false,
      storage: null,
      fetchBranding: async () => ({ application_name: '' }),
    })

    await state.load()

    expect(document.title).toBe('[es] screen.title – KronoQR')
    binding.stop()
  })

  it('applyBranding con manageTitle: false no escribe el titulo; por defecto si (quiosco)', () => {
    const { binding } = setup()
    const marina = { ...PRODUCT_BRANDING, applicationName: 'Hotel Marina' }

    applyBranding(marina, { mode: 'light', document, tokens: () => '#000000', manageTitle: false })
    expect(document.title).toBe('[es] screen.title – KronoQR')

    binding.stop()
    applyBranding(marina, { mode: 'light', document, tokens: () => '#000000' })
    expect(document.title).toBe('Hotel Marina')
  })

  it('tras stop() deja de reaccionar', () => {
    const { binding, name } = setup()

    binding.stop()
    name.value = 'Otro'

    expect(document.title).toBe('[es] screen.title – KronoQR')
  })
})
