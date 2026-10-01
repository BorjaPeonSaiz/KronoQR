// El atributo `lang` del documento sigue al idioma activo (WCAG 3.1.1, PA7-001).
//
// `index.html` declara `lang="es"` porque es el idioma por defecto, pero una
// cuenta en ingles navega con el documento declarado en español: un lector de
// pantalla pronunciaria el texto con las reglas del idioma equivocado. Axe no
// lo detecta porque el atributo es valido. Panel y portal llaman a esta funcion
// en el arranque; el quiosco ya lo hace a su manera.
import { watch, type WatchStopHandle } from 'vue'

/**
 * Mantiene `document.documentElement.lang` igual al idioma que devuelve
 * `source`, ahora y en cada cambio. Devuelve la funcion que detiene el
 * seguimiento.
 */
export function syncDocumentLang(
  source: () => string,
  target: Pick<HTMLElement, 'lang'> = document.documentElement,
): WatchStopHandle {
  return watch(
    source,
    (language) => {
      if (language !== '') {
        target.lang = language
      }
    },
    { immediate: true },
  )
}
