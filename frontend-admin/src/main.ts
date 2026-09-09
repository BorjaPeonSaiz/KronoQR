import { installClientErrorTransport } from '@kronoqr/web-kit/clientErrorTransport'
import { createWebErrorReporter, installGlobalErrorCapture } from '@kronoqr/web-kit/clientErrors'
import {
  setAuthTokenProvider,
  setLocaleProvider,
  setUnauthenticatedHandler,
} from '@kronoqr/web-kit/http'
import { VueQueryPlugin } from '@tanstack/vue-query'
import { createPinia } from 'pinia'
import { createApp, watch } from 'vue'
import App from './App.vue'
import './assets/main.css'
import { useSessionStore } from './features/auth/session.store'
import { createAppRouter } from './router'
import { registerAuthGuard } from './router/guards'
import { createAppQueryClient } from './shared/api/queryClient'
import { useBrandingStore } from './shared/branding/branding.store'
import { createAppI18n, isSupportedLocale, resolveLocale } from './shared/i18n'

const app = createApp(App)

// Antes que nada: un error durante el propio arranque tambien debe capturarse.
// El buffer saneado (regla dura 21: sin PII) se vacia hacia `error_events`
// por `installClientErrorTransport` en cuanto hay sesion (tarea 5.12).
const errorReporter = createWebErrorReporter({
  app: 'admin',
  appVersion: typeof __APP_VERSION__ !== 'undefined' ? __APP_VERSION__ : 'dev',
})
installGlobalErrorCapture(app, errorReporter)

const pinia = createPinia()

app.use(pinia)

const router = createAppRouter()
const i18n = createAppI18n(resolveLocale(navigator.languages))
const session = useSessionStore(pinia)

// La marca de la instalacion (RF-PD-08, tarea 5.8): se pinta lo que ya haya
// —el producto, siempre la primera vez— sin esperar a la red, y se pide al
// servidor SIN bloquear el primer pintado. Un fallo de red se ignora en
// silencio dentro del propio store: el panel arranca con el producto.
const branding = useBrandingStore(pinia)
branding.apply()
void branding.load()

// El cliente HTTP no conoce la tienda y la tienda no conoce al router: se atan
// aqui, en el arranque, que es el unico sitio donde se puede sin crear un ciclo.
setAuthTokenProvider(() => session.token)
setUnauthenticatedHandler(() => {
  session.clear()
  void router.push({ name: 'login' })
})

// Vacia el buffer de errores del panel hacia `error_events` (tarea 5.12,
// RF-PD-15): al pasar a autenticado, cada 60 s si hay pendientes y al
// ocultarse o cerrar la pestaña. Sin sesion, no manda nada -no hay canal
// anonimo-. El propio modulo NO sondea la sesion: se avisa de forma reactiva
// con `notifyAuthenticated()` desde el `watch` de abajo, con `immediate: true`
// para cubrir con el MISMO camino tanto pasar a autenticado como ya estarlo
// al recargar con un token todavia valido.
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
// 422, criterios de un informe). Se lee en cada peticion porque cambia al
// entrar: pasa a ser el de la persona (ver el `watch` de abajo).
setLocaleProvider(() => i18n.global.locale.value)

// El idioma del panel es el de la persona que entra, no el del navegador de la
// tablet en la que se ha dejado la sesion abierta.
watch(
  () => session.user?.locale,
  (userLocale) => {
    if (isSupportedLocale(userLocale)) {
      i18n.global.locale.value = userLocale
    }
  },
)

registerAuthGuard(router)

app.use(router)
app.use(i18n)
app.use(VueQueryPlugin, { queryClient: createAppQueryClient() })

app.mount('#app')
