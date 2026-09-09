// Transporte del buffer de `clientErrors.ts` hacia `error_events` (tarea 5.12,
// RF-PD-15, decision 7 de la ficha).
//
// SIN CANAL ANONIMO. El servidor decide `source` por el TIPO DE TOKEN de la
// sesion (gestion -> `admin`, portal -> `portal`), nunca por lo que diga el
// cuerpo: por eso este modulo NUNCA manda `app` ni `device_id`, aunque
// `WebErrorEvent` los lleve para agrupar en local. Sin sesion no hay nada que
// mandar -no existe un endpoint publico que escriba en base de datos-, asi
// que `isAuthenticated()` en `false` deja el buffer intacto para el siguiente
// intento.
//
// DISPARA en tres momentos, NINGUNO por sondeo:
//  1. `notifyAuthenticated()`, que llama quien instala este modulo (`main.ts`
//     del panel y del portal) desde un `watch` de `session.isAuthenticated`
//     con `immediate: true` -asi cubre tanto pasar a autenticado como ya
//     estarlo al arrancar, con el MISMO camino de codigo-. Este modulo no
//     sondea la sesion por su cuenta: hacerlo con un `setInterval` corto
//     malgastaria ciclos en cada pestaña abierta para detectar un cambio que
//     quien tiene la sesion ya sabe cuando ocurre.
//  2. Cada `intervalMs` (60 s por omision), y SOLO si queda algo pendiente.
//  3. Al ocultarse o cerrarse la pestaña (`visibilitychange`/`pagehide`), con
//     `fetch(..., { keepalive: true })`: la peticion sigue viva aunque la
//     pagina se descargue, pero la respuesta puede no llegar a leerse, asi
//     que ese envio NO confirma (`acknowledge`) nada. El servidor agrupa por
//     huella (decision 4 de la ficha): un reenvio en el peor caso duplica un
//     `occurrences`, nunca una fila.
//
// UN FALLO DEL TRANSPORTE NO SE REPORTA A SI MISMO (evitaria un bucle
// reportar -> fallar -> reportar) y NUNCA reintenta agresivamente: un fallo de
// red se ignora y se vuelve a intentar en el siguiente tick, sea cual sea la
// causa.
import { requestJson } from './http'
import type { WebErrorEvent, WebErrorReporter } from './clientErrors'

/** Cada cuanto se repite el envio periodico si queda algo pendiente. */
const DEFAULT_INTERVAL_MS = 60_000

/** Maximo por envio (contrato, `ClientErrorBatch.errors`). */
const MAX_BATCH = 50

const ENDPOINT = '/api/v1/client-errors'

/** El cuerpo que de verdad acepta el contrato: sin `app` ni identidad alguna. */
interface ClientErrorReportPayload {
  code: string
  occurred_at: string
  app_version: string
  context: Record<string, string | number | boolean>
}

interface ClientErrorsAcceptedPayload {
  accepted: number
}

function toPayload(event: WebErrorEvent): ClientErrorReportPayload {
  return {
    code: event.code,
    occurred_at: event.occurred_at,
    app_version: event.app_version,
    context: event.context,
  }
}

export interface ClientErrorTransportOptions {
  readonly reporter: WebErrorReporter
  /** Si hay una sesion con la que mandar. Sin sesion, nunca se manda nada. */
  readonly isAuthenticated: () => boolean
  readonly intervalMs?: number
}

export interface ClientErrorTransport {
  /** Fuerza un intento de vaciado ya mismo. Para pruebas y para el propio modulo. */
  flush: () => Promise<void>
  /**
   * Avisa de que la sesion acaba de estar disponible -o ya lo estaba al
   * arrancar-, para vaciar el buffer sin esperar al intervalo periodico. Quien
   * instala este modulo la llama desde un `watch` reactivo de su propia
   * sesion (`main.ts`); este modulo no vigila la sesion por su cuenta.
   */
  notifyAuthenticated: () => void
  /** Detiene el temporizador periodico y quita los listeners. Uso en pruebas o al desmontar. */
  stop: () => void
}

export function installClientErrorTransport(
  options: ClientErrorTransportOptions,
): ClientErrorTransport {
  const { reporter, isAuthenticated } = options
  const intervalMs = options.intervalMs ?? DEFAULT_INTERVAL_MS

  let inFlight = false

  async function flush(): Promise<void> {
    if (!isAuthenticated() || inFlight) {
      return
    }

    const pending = reporter.pending().slice(0, MAX_BATCH)

    if (pending.length === 0) {
      return
    }

    inFlight = true

    try {
      const response = await requestJson<ClientErrorsAcceptedPayload>(ENDPOINT, {
        method: 'POST',
        body: { errors: pending.map(toPayload) },
      })

      reporter.acknowledge(response.accepted)
    } catch {
      // Un fallo de red o del servidor se ignora: se reintenta en el
      // siguiente tick, sin marcar nada como confirmado. Nunca se reporta
      // este fallo con el propio `reporter` (bucle) ni se reintenta aqui
      // mismo (reintento agresivo).
    } finally {
      inFlight = false
    }
  }

  function notifyAuthenticated(): void {
    void flush()
  }

  const periodicTimer = setInterval(() => {
    if (isAuthenticated()) {
      void flush()
    }
  }, intervalMs)

  /**
   * Envio de mejor esfuerzo con `keepalive` al ocultarse o cerrarse la
   * pestaña. No espera respuesta de verdad (la pagina puede descargarse antes
   * de que llegue) y por eso NUNCA llama a `reporter.acknowledge`: confirmar
   * sin haber leido la respuesta arriesgaria a vaciar el buffer sin que el
   * servidor lo haya recibido.
   */
  function sendBestEffort(): void {
    if (!isAuthenticated()) {
      return
    }

    const pending = reporter.pending().slice(0, MAX_BATCH)

    if (pending.length === 0) {
      return
    }

    void requestJson<ClientErrorsAcceptedPayload>(ENDPOINT, {
      method: 'POST',
      body: { errors: pending.map(toPayload) },
      keepalive: true,
    }).catch(() => {
      // Mejor esfuerzo: un fallo aqui no tiene a donde ir. La pagina se esta
      // cerrando u ocultando; lo pendiente sigue en el buffer para el
      // siguiente arranque.
    })
  }

  function handleVisibilityChange(): void {
    if (document.visibilityState === 'hidden') {
      sendBestEffort()
    }
  }

  function handlePageHide(): void {
    sendBestEffort()
  }

  document.addEventListener('visibilitychange', handleVisibilityChange)
  window.addEventListener('pagehide', handlePageHide)

  function stop(): void {
    clearInterval(periodicTimer)
    document.removeEventListener('visibilitychange', handleVisibilityChange)
    window.removeEventListener('pagehide', handlePageHide)
  }

  return { flush, notifyAuthenticated, stop }
}
