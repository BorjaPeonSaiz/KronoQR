// Los catalogos completos para las pruebas: la aplicacion los descarga por idioma
// (`ensureLocaleLoaded`), pero una prueba monta sincrona y los pasa ya cargados.
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'

export const testMessages = { es, en }
