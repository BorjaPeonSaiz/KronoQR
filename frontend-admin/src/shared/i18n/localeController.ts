// Arranque y cambio de idioma con catalogos que se descargan por idioma.
//
// Vive fuera de `main.ts` para poder probarlo: tres casos que no pueden fallar en
// silencio.
//
//  - **El idioma pedido cambia mientras se descarga el catalogo** (la marca de la
//    instalacion llega despues, o entra otra persona): al terminar solo se aplica si
//    sigue siendo el que se quiere ahora. Sin esa comprobacion, una descarga lenta
//    pisaba al idioma bueno.
//  - **El catalogo de arranque no llega**: se reintenta una vez y, si sigue sin
//    llegar, se cae al de reserva (`es`). Montar sin textos pintaria claves.
//  - **Tampoco llega el de reserva**: no se monta; se enseña un fallo visible y
//    bilingue (sin catalogo no hay `t()`) con un boton para recargar.
import { DEFAULT_LOCALE, ensureLocaleLoaded } from './index'
import type { AppI18n, AppLocale } from './index'

export type StartupOutcome = 'ok' | 'fallback' | 'failed'

export interface LocaleController {
  /** Cambia de idioma cuando el catalogo esta descargado y sigue siendo el deseado. */
  applyLocale: (locale: AppLocale) => Promise<void>
  /** Carga el idioma de arranque (con reintento y reserva). Hay que esperarlo antes de montar. */
  startup: (initial: AppLocale) => Promise<StartupOutcome>
}

export function createLocaleController(
  i18n: AppI18n,
  /** El idioma que se quiere AHORA (puede cambiar mientras se descarga otro). */
  wanted: () => AppLocale,
  load: (i18n: AppI18n, locale: AppLocale) => Promise<void> = ensureLocaleLoaded,
): LocaleController {
  async function applyLocale(locale: AppLocale): Promise<void> {
    try {
      await load(i18n, locale)
    } catch {
      // El idioma anterior sigue en pantalla; el siguiente cambio lo reintenta.
      return
    }

    if (wanted() === locale) {
      i18n.global.locale.value = locale
    }
  }

  async function tryLoad(locale: AppLocale): Promise<boolean> {
    try {
      await load(i18n, locale)

      return true
    } catch {
      return false
    }
  }

  async function startup(initial: AppLocale): Promise<StartupOutcome> {
    if ((await tryLoad(initial)) || (await tryLoad(initial))) {
      i18n.global.locale.value = initial

      // La marca pudo llegar mientras bajaba el catalogo: si el deseado ya es otro, se va a el.
      if (wanted() !== initial) {
        await applyLocale(wanted())
      }

      return 'ok'
    }

    if (initial !== DEFAULT_LOCALE && (await tryLoad(DEFAULT_LOCALE))) {
      i18n.global.locale.value = DEFAULT_LOCALE

      return 'fallback'
    }

    return 'failed'
  }

  return { applyLocale, startup }
}

/** El fallo de arranque, sin `t()` (no hay catalogo): visible, en los dos idiomas y con una salida. */
export function renderBootFailure(root: HTMLElement | null): void {
  if (root === null) {
    return
  }

  const box = document.createElement('div')
  const title = document.createElement('p')
  const advice = document.createElement('p')
  const button = document.createElement('button')

  box.setAttribute('role', 'alert')
  box.dataset['test'] = 'boot-failure'
  box.style.cssText = 'max-width:40rem;margin:3rem auto;padding:1rem;font-family:sans-serif'
  title.style.fontWeight = '700'
  title.textContent =
    'No se han podido cargar los textos de la aplicación. / The application texts could not be loaded.'
  advice.textContent =
    'Comprueba la conexión y recarga la página. Si sigue igual, avisa a quien administra la instalación. / Check the connection and reload. If it persists, tell whoever administers the installation.'
  button.type = 'button'
  button.textContent = 'Recargar / Reload'
  button.addEventListener('click', () => window.location.reload())
  box.append(title, advice, button)
  root.replaceChildren(box)
}
