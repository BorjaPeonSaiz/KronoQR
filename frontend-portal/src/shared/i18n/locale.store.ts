// El idioma que la persona elige a mano con el selector (D22, PO7-05).
//
// Vive en memoria y no en el navegador: la regla de almacenamiento del portal
// (`no-browser-storage`) reserva `sessionStorage` a la sesion de la propia
// persona, y un movil o un ordenador del centro compartido no tiene por que
// recordar el idioma de quien lo uso antes. Se conserva mientras la pestana
// siga abierta, tambien al salir y volver a entrar; al recargar vuelve a mandar
// el idioma de la persona (`employees.locale`) y despues el del navegador.
import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { AppLocale } from './index'

export const useLocaleChoiceStore = defineStore('portal-locale-choice', () => {
  const chosen = ref<AppLocale | null>(null)

  function choose(locale: AppLocale): void {
    chosen.value = locale
  }

  return { chosen, choose }
})
