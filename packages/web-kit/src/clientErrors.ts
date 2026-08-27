// Captura de errores de las SPA de gestion y del portal (regla dura 21).
//
// POR QUE EXISTE. El quiosco tiene su propio canal desde la 1.8
// (frontend-kiosk/src/shared/telemetry/errorReporter.ts); el panel y el portal
// no tenian NINGUNO: ni `app.config.errorHandler`, ni `window.onerror` — un
// error de produccion solo existia si la persona lo contaba. La auditoria de
// cierre de la Fase 1 lo señalo como el hueco real que un error tracking
// externo habria tapado, y este modulo lo cierra dentro del modelo del
// producto (ADR-016/020: los datos no salen de la instalacion).
//
// QUE HACE HOY. Un unico punto de paso: sanea el contexto, agrupa en un buffer
// acotado y deja el error tambien en la consola (capturar jamas significa
// silenciar). El TRANSPORTE llega con `error_events` (tarea 5.12): cuando
// exista, `pending()`/`acknowledge()` son la misma pareja que ya drena el
// reporter del quiosco por el latido.
//
// QUE NO PUEDE LLEVAR NUNCA. Datos personales. El saneado es el mismo contrato
// que el del quiosco: descarta claves prohibidas por nombre, tipos no
// escalares, y trunca cadenas. No se envia `stack` (una URL con un uuid dentro
// correlaciona a una persona) — el codigo estable + mensaje + componente
// bastan para agrupar.

import type { App } from 'vue'

const MAX_BUFFERED_ERRORS = 50
const MAX_CONTEXT_KEYS = 12
const MAX_STRING_LENGTH = 200

/**
 * Claves cuyo valor no se guarda nunca, se llame como se llame el sitio desde
 * el que se reporta. Copia deliberada del contrato del quiosco: es una red de
 * seguridad contra el descuido de mañana.
 */
const FORBIDDEN_CONTEXT_KEYS = [
  'name',
  'employee',
  'display_name',
  'displayname',
  'payload',
  'qr',
  'qr_payload',
  'token',
  'secret',
  'hash',
  'pin',
  'email',
  'phone',
  'dni',
] as const

export type ContextValue = string | number | boolean
export type ErrorContext = Readonly<Record<string, ContextValue>>

/**
 * Codigos estables y cerrados, como en el quiosco: un codigo libre acaba
 * siendo una frase, y una frase acaba llevando un nombre dentro.
 */
export type WebErrorCode = 'web.vue_error' | 'web.unhandled_error' | 'web.unhandled_rejection'

export interface WebErrorEvent {
  readonly code: WebErrorCode
  readonly occurred_at: string
  readonly app: string
  readonly app_version: string
  readonly context: ErrorContext
}

export function sanitizeContext(input: Readonly<Record<string, unknown>>): ErrorContext {
  const output: Record<string, ContextValue> = {}
  let kept = 0

  for (const [key, value] of Object.entries(input)) {
    if (kept >= MAX_CONTEXT_KEYS) break

    const normalizedKey = key.toLowerCase()
    if (FORBIDDEN_CONTEXT_KEYS.some((forbidden) => normalizedKey.includes(forbidden))) continue

    if (typeof value === 'number') {
      if (!Number.isFinite(value)) continue
      output[key] = value
    } else if (typeof value === 'boolean') {
      output[key] = value
    } else if (typeof value === 'string') {
      output[key] = value.slice(0, MAX_STRING_LENGTH)
    } else {
      // Objetos, arrays, funciones y `null` no entran: son la via por la que
      // se cuela una estructura entera con datos dentro.
      continue
    }
    kept += 1
  }

  return output
}

export interface WebErrorReporterOptions {
  /** `admin` o `portal`: distingue el origen cuando ambos acaben en `error_events`. */
  readonly app: string
  readonly appVersion: string
  readonly now?: () => Date
  readonly maxBuffered?: number
}

export interface WebErrorReporter {
  report(code: WebErrorCode, context?: Readonly<Record<string, unknown>>): void
  /** Copia de lo pendiente. No vacia el buffer: eso lo hace `acknowledge`. */
  pending(): readonly WebErrorEvent[]
  /** Descarta los `count` mas antiguos, una vez confirmados por el servidor. */
  acknowledge(count: number): void
  size(): number
}

export function createWebErrorReporter(options: WebErrorReporterOptions): WebErrorReporter {
  const now = options.now ?? (() => new Date())
  const limit = options.maxBuffered ?? MAX_BUFFERED_ERRORS
  const buffer: WebErrorEvent[] = []

  return {
    report(code, context = {}) {
      buffer.push({
        code,
        occurred_at: now().toISOString(),
        app: options.app,
        app_version: options.appVersion,
        context: sanitizeContext(context),
      })
      // Techo duro: un error que se repite en un bucle de render llenaria la
      // memoria, y ese seria un fallo peor que el que se intentaba diagnosticar.
      while (buffer.length > limit) buffer.shift()
    },

    pending() {
      return [...buffer]
    },

    acknowledge(count) {
      buffer.splice(0, Math.max(0, count))
    },

    size() {
      return buffer.length
    },
  }
}

function describe(error: unknown): string {
  if (error instanceof Error) return `${error.name}: ${error.message}`
  return typeof error === 'string' ? error : Object.prototype.toString.call(error)
}

/**
 * Engancha el reporter a los tres puntos por los que un error puede escapar:
 * el arbol de componentes de Vue, los errores globales de `window` y las
 * promesas rechazadas sin `catch`. Capturar no silencia: todo sigue saliendo
 * por `console.error` para quien depure con la consola abierta.
 */
export function installGlobalErrorCapture(app: App, reporter: WebErrorReporter): void {
  app.config.errorHandler = (error, instance, info) => {
    reporter.report('web.vue_error', {
      message: describe(error),
      component: instance?.$options.name ?? '(anonimo)',
      hook: info,
    })
    console.error(error)
  }

  window.addEventListener('error', (event) => {
    reporter.report('web.unhandled_error', {
      message: describe(event.error ?? event.message),
      source: `${event.filename ?? ''}:${event.lineno ?? 0}`,
    })
  })

  window.addEventListener('unhandledrejection', (event) => {
    reporter.report('web.unhandled_rejection', {
      message: describe(event.reason),
    })
  })
}
