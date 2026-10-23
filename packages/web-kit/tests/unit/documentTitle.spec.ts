// Titulo de la pestaña por pantalla (WCAG 2.4.2, V7-PA-2, R7-PO-03), compartido por el panel y el
// portal. El caso que importa: `applyBranding` reescribe el titulo con el nombre a secas SIEMPRE
// al terminar, tambien cuando la marca falla o llega invalida y no cambia nada reactivo.
import { describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'
import { bindDocumentTitle } from '../../src/documentTitle'

function setup() {
  document.head.innerHTML = '<title>KronoQR</title>'
  const locale = ref('es')
  const currentRoute = ref({ meta: { titleKey: 'screen.title' as string | undefined } })
  const binding = bindDocumentTitle({
    router: { currentRoute },
    i18n: { global: { t: (key: string) => `[${locale.value}] ${key}`, locale } },
    applicationName: () => 'KronoQR',
  })

  return { binding, locale, currentRoute }
}

/** Deja pasar el callback del MutationObserver. */
const settle = async (): Promise<void> => {
  await nextTick()
  await new Promise<void>((resolve) => setTimeout(resolve, 0))
}

describe('bindDocumentTitle', () => {
  it('compone «pantalla – instalacion» y sigue al idioma y a la ruta', async () => {
    const { binding, locale, currentRoute } = setup()

    expect(document.title).toBe('[es] screen.title – KronoQR')

    locale.value = 'en'
    expect(document.title).toBe('[en] screen.title – KronoQR')

    currentRoute.value = { meta: { titleKey: undefined } }
    expect(document.title).toBe('KronoQR')

    binding.stop()
  })

  it('si la marca falla y reescribe el titulo a secas, vuelve a componerlo', async () => {
    const { binding } = setup()

    // Lo que hace `applyBranding` al terminar aunque la peticion haya fallado: nada reactivo cambia.
    document.title = 'KronoQR'
    await settle()

    expect(document.title).toBe('[es] screen.title – KronoQR')

    binding.stop()
  })

  it('tras stop() deja de vigilar el titulo', async () => {
    const { binding } = setup()

    binding.stop()
    document.title = 'Otro'
    await settle()

    expect(document.title).toBe('Otro')
  })
})
