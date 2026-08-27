import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import './assets/main.css'
import { canApplyUpdate } from './features/offline/domain/updateWindow'
import { pendingScanCount } from './features/offline/useOfflineQueue'
import { createAppRouter } from './router'
import { createAppI18n, initialLocale } from './shared/i18n'
import { APP_VERSION, resolveDeviceId } from './shared/telemetry/deviceIdentity'
import { createErrorReporter } from './shared/telemetry/errorReporter'
import { errorTypeOf } from './shared/telemetry/errorType'
import { registerServiceWorker } from './sw/registerServiceWorker'

const locale = initialLocale()
document.documentElement.lang = locale

const app = createApp(App)

// Red de seguridad de errores (RF-PD-15, regla dura 21). Sin esto, un fallo de
// render en una tablet colgada de una pared no lo ve nadie hasta que alguien
// reclama una jornada. Nunca lleva datos personales: `sanitizeContext` los
// descarta por nombre de clave y por tipo.
const bootReporter = createErrorReporter({
  appVersion: APP_VERSION,
  deviceId: resolveDeviceId(),
})

app.config.errorHandler = (error: unknown) => {
  bootReporter.report('kiosk.unhandled_error', {
    error_type: errorTypeOf(error),
    error_message: error instanceof Error ? error.message : '',
    scope: 'vue',
  })
}

window.addEventListener('error', (event) => {
  bootReporter.report('kiosk.unhandled_error', {
    error_message: event.message,
    source: event.filename,
    line: event.lineno,
    scope: 'window',
  })
})

window.addEventListener('unhandledrejection', (event) => {
  bootReporter.report('kiosk.unhandled_error', {
    error_type: errorTypeOf(event.reason),
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
