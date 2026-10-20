import { installClientErrorTransport } from '@kronoqr/web-kit/clientErrorTransport'
import { createWebErrorReporter, installGlobalErrorCapture } from '@kronoqr/web-kit/clientErrors'
import { syncDocumentLang } from '@kronoqr/web-kit/documentLang'
import { setAuthTokenProvider, setLocaleProvider } from '@kronoqr/web-kit/http'
import { createPinia } from 'pinia'
import { createApp, watch } from 'vue'
import App from './App.vue'
import './assets/main.css'
import { useSessionStore } from './features/login/session.store'
import { createAppRouter } from './router'
import { bindDocumentTitle } from './router/documentTitle'
import { registerAuthGuard } from './router/guards'
import { useBrandingStore } from './shared/branding/branding.store'
import { createAppI18n } from './shared/i18n'
import { bindPortalLocale } from './shared/i18n/portalLocale'
import { bindSessionLifecycle } from './shared/session/sessionLifecycle'

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
const session = useSessionStore(pinia)
const branding = useBrandingStore(pinia)

const i18n = createAppI18n()

// El cliente HTTP no conoce la tienda y la tienda no conoce al router: se atan
// aqui, en el arranque, que es el unico sitio donde se puede sin crear un ciclo.
setAuthTokenProvider(() => session.token)
bindSessionLifecycle({ router, session })

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

// El documento declara el idioma que se esta mostrando (WCAG 3.1.1, PA7-001).
syncDocumentLang(() => i18n.global.locale.value)

// El idioma lo decide la politica de la marca (`branding.locales`): con un
// solo idioma ofrecido -el de serie, espanol- ni el navegador ni el idioma de
// la persona lo cambian. Con varios gana el de la persona que ha entrado
// (`employees.locale`, doc 01 §6.6) y despues el del navegador. Se recalcula
// al entrar o salir una persona y al llegar la marca del servidor.
bindPortalLocale(i18n, () => ({
  policy: branding.current.locales,
  personal: session.employee?.locale,
}))

registerAuthGuard(router)

// Marca de la instalacion (RF-PD-08). Se pinta el producto de inmediato -sin
// esperar a la red, para no retrasar la primera pantalla- y se pide la marca
// real en paralelo; si llega y es valida, sustituye a la del producto.
branding.apply()

// Titulo de la pestaña por pantalla (WCAG 2.4.2): la marca escribe el nombre de
// la instalacion, esto le antepone la pantalla. Se recompone tras cada vez que
// la marca se aplica.
const documentTitle = bindDocumentTitle({
  router,
  i18n,
  applicationName: () => branding.current.applicationName,
})

void branding.load().then(documentTitle.refresh)

app.use(router)
app.use(i18n)

app.mount('#app')
