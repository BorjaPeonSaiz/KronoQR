import { installClientErrorTransport } from '@kronoqr/web-kit/clientErrorTransport'
import { createWebErrorReporter, installGlobalErrorCapture } from '@kronoqr/web-kit/clientErrors'
import {
  setAuthTokenProvider,
  setLocaleProvider,
  setUnauthenticatedHandler,
} from '@kronoqr/web-kit/http'
import { createPinia } from 'pinia'
import { createApp, watch } from 'vue'
import App from './App.vue'
import './assets/main.css'
import { useSessionStore } from './features/login/session.store'
import { createAppRouter } from './router'
import { registerAuthGuard } from './router/guards'
import { useBrandingStore } from './shared/branding/branding.store'
import {
  browserHasSupportedLocale,
  createAppI18n,
  isSupportedLocale,
  resolveLocale,
} from './shared/i18n'

const app = createApp(App)

// Antes que nada: un error durante el propio arranque tambien debe capturarse.
// El buffer saneado (regla dura 21: sin PII) se vacia hacia `error_events`
// por `installClientErrorTransport` en cuanto hay sesion (tarea 5.12).
const errorReporter = createWebErrorReporter({
  app: 'portal',
  appVersion: typeof __APP_VERSION__ !== 'undefined' ? __APP_VERSION__ : 'dev',
})
installGlobalErrorCapture(app, errorReporter)

const pinia = createPinia()

app.use(pinia)

const router = createAppRouter()
const i18n = createAppI18n(resolveLocale(navigator.languages))
const session = useSessionStore(pinia)

// El cliente HTTP no conoce la tienda y la tienda no conoce al router: se atan
// aqui, en el arranque, que es el unico sitio donde se puede sin crear un ciclo.
setAuthTokenProvider(() => session.token)
setUnauthenticatedHandler(() => {
  session.clear()
  void router.push({ name: 'login' })
})

// Vacia el buffer de errores del portal hacia `error_events` (tarea 5.12,
// RF-PD-15): al pasar a autenticado, cada 60 s si hay pendientes y al
// ocultarse o cerrar la pestaña. Sin sesion, no manda nada -no hay canal
// anonimo-. El propio modulo NO sondea la sesion: se avisa de forma reactiva
// con `notifyAuthenticated()` desde un `watch` con `immediate: true`, para
// cubrir con el MISMO camino tanto pasar a autenticado como ya estarlo al
// recargar con un token todavia valido.
const clientErrorTransport = installClientErrorTransport({
  reporter: errorReporter,
  isAuthenticated: () => session.isAuthenticated,
})

watch(
  () => session.isAuthenticated,
  (authenticated) => {
    if (authenticated) {
      clientErrorTransport.notifyAuthenticated()
    }
  },
  { immediate: true },
)

// El servidor escribe en este idioma lo que lee una persona (mensajes de un
// 422). Se lee en cada peticion porque cambia al entrar: pasa a ser el de la
// persona (ver el `watch` de abajo).
setLocaleProvider(() => i18n.global.locale.value)

// El idioma del portal es el de la persona que ha entrado (`employees.locale`),
// no el del navegador desde el que mira ni el del dispositivo compartido del
// centro (doc 01 §6.6, PortalEmployee.locale).
watch(
  () => session.employee?.locale,
  (employeeLocale) => {
    if (isSupportedLocale(employeeLocale)) {
      i18n.global.locale.value = employeeLocale
    }
  },
  { immediate: true },
)

registerAuthGuard(router)

// Marca de la instalacion (RF-PD-08). Se pinta el producto de inmediato -sin
// esperar a la red, para no retrasar la primera pantalla- y se pide la marca
// real en paralelo; si llega y es valida, sustituye a la del producto.
const branding = useBrandingStore(pinia)
branding.apply()
void branding.load().then(() => {
  // Solo cuando nadie ha elegido idioma todavia: ni el navegador pedia uno
  // soportado (`browserHasSupportedLocale`) ni hay una persona identificada
  // cuyo `employees.locale` ya haya fijado el idioma (el `watch` de abajo).
  // El idioma de la instalacion nunca gana a una preferencia real.
  if (
    session.employee === null &&
    !browserHasSupportedLocale(navigator.languages) &&
    isSupportedLocale(branding.current.locales.default)
  ) {
    i18n.global.locale.value = branding.current.locales.default
  }
})

app.use(router)
app.use(i18n)

app.mount('#app')
