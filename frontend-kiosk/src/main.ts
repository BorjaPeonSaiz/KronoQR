import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import './assets/main.css'
import { canApplyUpdate } from './features/offline/domain/updateWindow'
import { pendingScanCount } from './features/offline/useOfflineQueue'
import { createAppRouter } from './router'
import { applyCachedBranding } from './shared/branding/useBranding'
import { createAppI18n, initialLocale } from './shared/i18n'
import { APP_VERSION, resolveDeviceId } from './shared/telemetry/deviceIdentity'
import { getErrorReporter } from './shared/telemetry/errorReporter'
import { errorMessageOf, errorTypeOf } from './shared/telemetry/errorType'
import { registerServiceWorker } from './sw/registerServiceWorker'

const locale = initialLocale()
document.documentElement.lang = locale

// Marca blanca (RF-PD-08): la copia guardada -o la del producto, si no hay
// ninguna- ANTES de montar nada. Sin red, sin `await`: es lo que evita un
// parpadeo del nombre en el primer fotograma. `ScanView.vue` pide la version
// fresca al servidor en segundo plano (`shared/branding/useBranding.ts`).
applyCachedBranding()

const app = createApp(App)

// Red de seguridad de errores (RF-PD-15, regla dura 21). Sin esto, un fallo de
// render en una tablet colgada de una pared no lo ve nadie hasta que alguien
// reclama una jornada. Nunca lleva datos personales: `sanitizeContext` los
// descarta por nombre de clave y por tipo.
//
// `getErrorReporter`, no `createErrorReporter`: es el MISMO reporter que
// `ScanView.vue`/`PinView.vue` piden despues (singleton por tablet, ver
// `errorReporter.ts`). Sin esto, el latido de la pantalla nunca llegaria a
// drenar los errores de arranque -los mas graves, porque son los que impiden
// que nada mas funcione-.
const bootReporter = getErrorReporter({
  appVersion: APP_VERSION,
  deviceId: resolveDeviceId(),
})

app.config.errorHandler = (error: unknown) => {
  bootReporter.report('kiosk.unhandled_error', {
    error_type: errorTypeOf(error),
    // Clave canonica del texto del error (RF-PD-15): el servidor la toma tal
    // cual para la columna `message` de `error_events`.
    message: errorMessageOf(error),
    scope: 'vue',
  })
}

window.addEventListener('error', (event) => {
  bootReporter.report('kiosk.unhandled_error', {
    message: event.message,
    source: event.filename,
    line: event.lineno,
    scope: 'window',
  })
})

window.addEventListener('unhandledrejection', (event) => {
  bootReporter.report('kiosk.unhandled_error', {
    error_type: errorTypeOf(event.reason),
    // Antes vacio: una promesa rechazada con un `Error` de verdad -el caso mas
    // comun- se quedaba sin nada legible mas alla del codigo.
    message: errorMessageOf(event.reason),
    scope: 'promise',
  })
})

app.use(createPinia())
app.use(createAppRouter())
app.use(createAppI18n(locale))

app.mount('#app')

// Fuera del camino de montaje: que el service worker tarde en registrarse no
// puede retrasar la primera pantalla.
//
// `canApply` es la puerta del paso 11 de la tarea 1.9: aunque alguien pida
// aplicar una version nueva, no se recarga durante un cambio de turno ni con
// fichajes sin sincronizar. La ventana configurable por cliente es RF-KI-07
// (tarea 3.12); esto es lo que impide que ocurra a ciegas mientras tanto.
void registerServiceWorker({
  onError: (context) => bootReporter.report('kiosk.service_worker.failed', context),
  canApply: () => canApplyUpdate({ now: new Date(), pendingScans: pendingScanCount() }),
})
