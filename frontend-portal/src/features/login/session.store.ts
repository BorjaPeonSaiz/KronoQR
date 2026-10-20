// Estado de la sesion del portal (RF-ID-07, ADR-015).
//
// Que se guarda y donde, que es la decision que importa:
//
//  - El token, su caducidad y los datos de quien ha entrado van a
//    `sessionStorage`, no a `localStorage`: la sesion muere con la pestaña. El
//    portal se abre con frecuencia desde un movil personal o desde un
//    ordenador del centro que usa mas gente, y una sesion que sobreviviera a
//    cerrar el navegador seria el turno de otra persona leyendo estas horas.
//  - **Estos SI son los datos propios de quien ha entrado** (su nombre, su
//    zona, su idioma): no es el mismo caso que un log tecnico o que el padron
//    del quiosco, donde minimizar protege a un tercero. Aqui la unica persona
//    a la que protege minimizar es la misma que ya los tiene delante.
//  - **El PIN nunca llega a este fichero.** Vive en el estado efimero del
//    formulario de acceso y se descarta en cuanto se envia (regla dura 21).
//  - **Salir cierra la sesion tambien en el servidor** (`POST /api/v1/me/logout`,
//    PO1): el ordenador compartido del centro no puede dejar un token vivo hasta
//    su caducidad. La llamada lleva un tiempo maximo corto y NUNCA bloquea la
//    salida: la sesion local se borra siempre, salga lo que salga (204, 401, 403,
//    429, error de red o tiempo agotado).
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { PortalEmployee, PortalLoginRequest } from '@/shared/api/types'
import { useLocaleChoiceStore } from '@/shared/i18n/locale.store'
import { logInToPortal as logInRequest, logOutOfPortal as logOutRequest } from './login.api'

const STORAGE_KEY = 'kronoqr.portal.session'

/** Lo maximo que se espera al servidor al salir; pasado esto se sale igualmente. */
export const LOGOUT_TIMEOUT_MS = 3000

interface StoredPortalSession {
  token: string
  expiresAt: string
  employee: PortalEmployee
}

function isPortalEmployee(value: unknown): value is PortalEmployee {
  return (
    typeof value === 'object' &&
    value !== null &&
    typeof (value as PortalEmployee).uuid === 'string' &&
    typeof (value as PortalEmployee).display_name === 'string' &&
    typeof (value as PortalEmployee).employee_code === 'string' &&
    typeof (value as PortalEmployee).locale === 'string' &&
    typeof (value as PortalEmployee).time_zone === 'string'
  )
}

function readStored(): StoredPortalSession | null {
  try {
    const raw = globalThis.sessionStorage?.getItem(STORAGE_KEY) ?? null

    if (raw === null) {
      return null
    }

    const parsed: unknown = JSON.parse(raw)

    if (
      typeof parsed === 'object' &&
      parsed !== null &&
      typeof (parsed as StoredPortalSession).token === 'string' &&
      typeof (parsed as StoredPortalSession).expiresAt === 'string' &&
      isPortalEmployee((parsed as StoredPortalSession).employee)
    ) {
      return parsed as StoredPortalSession
    }
  } catch {
    // Un almacenamiento ilegible es una sesion que no existe, no un fallo que
    // deba impedir entrar.
  }

  return null
}

function writeStored(value: StoredPortalSession | null): void {
  try {
    if (value === null) {
      globalThis.sessionStorage?.removeItem(STORAGE_KEY)
    } else {
      globalThis.sessionStorage?.setItem(STORAGE_KEY, JSON.stringify(value))
    }
  } catch {
    // Navegador con almacenamiento bloqueado: la sesion vive en memoria y se
    // pierde al recargar. Es peor experiencia, no un fallo de seguridad.
  }
}

/** Instante de caducidad en ms, o `null` si no hay caducidad legible. */
function expiryInstant(expiresAt: string | null): number | null {
  if (expiresAt === null) {
    return null
  }

  const instant = new Date(expiresAt).getTime()

  return Number.isFinite(instant) ? instant : null
}

/** `setTimeout` guarda el retardo en 32 bits con signo: pasado esto se dispara al instante. */
const MAX_TIMER_MS = 2_147_483_647

/** Por que ya no hay sesion, cuando no ha sido porque la persona pulsa «Salir». */
export type SessionEndReason = 'expired'

export const useSessionStore = defineStore('portal-session', () => {
  // Una sesion guardada que ya caduco no se recupera: se descarta y se avisa en
  // el acceso. Se comprueba aqui, una vez, y no dentro de un `computed`: un
  // `computed` que lee el reloj no se reevalua cuando el reloj avanza.
  const loaded = readStored()
  const loadedExpiry = expiryInstant(loaded?.expiresAt ?? null)
  const stored = loadedExpiry !== null && loadedExpiry <= Date.now() ? null : loaded

  const token = ref<string | null>(stored?.token ?? null)
  const expiresAt = ref<string | null>(stored?.expiresAt ?? null)
  const employee = ref<PortalEmployee | null>(stored?.employee ?? null)
  /** Se pone cuando la sesion termina sola (caducidad o `401`); se limpia al entrar. */
  const endedReason = ref<SessionEndReason | null>(
    loaded !== null && stored === null ? 'expired' : null,
  )
  let expiryTimer: ReturnType<typeof setTimeout> | null = null

  if (loaded !== null && stored === null) {
    writeStored(null)
  }

  /**
   * Hay sesion mientras haya token. La caducidad no se lee aqui: la hace valer
   * `expireIfDue`, llamado por un temporizador hasta `expires_at` y al volver a
   * la pestana. No hay `GET /me` que confirme el token contra el servidor: uno
   * revocado en el servidor se descubre en la primera peticion real, y el
   * gestor de `401` de `@kronoqr/web-kit/http` llama a `expire()`.
   */
  const isAuthenticated = computed(() => token.value !== null)

  function stopExpiryTimer(): void {
    if (expiryTimer !== null) {
      clearTimeout(expiryTimer)
      expiryTimer = null
    }
  }

  function clear(): void {
    stopExpiryTimer()
    token.value = null
    expiresAt.value = null
    employee.value = null
    writeStored(null)
    // El idioma elegido a mano era de quien estaba delante: no pasa a la persona
    // siguiente del mismo dispositivo.
    useLocaleChoiceStore().reset()
  }

  /**
   * La sesion termina sola: caducidad o `401` con la sesion abierta. Borra lo
   * local y deja dicho por que, para que el acceso lo explique. Sin sesion no
   * hace nada: un `401` que llega tras pulsar «Salir» no es una caducidad.
   */
  function expire(): void {
    if (token.value === null) {
      return
    }

    clear()
    endedReason.value = 'expired'
  }

  /** Cierra la sesion si `expires_at` ya paso; si no, vuelve a armar el temporizador. */
  function expireIfDue(): void {
    const instant = expiryInstant(expiresAt.value)

    if (token.value === null || instant === null) {
      return
    }

    if (instant <= Date.now()) {
      expire()

      return
    }

    stopExpiryTimer()
    expiryTimer = setTimeout(expireIfDue, Math.min(instant - Date.now(), MAX_TIMER_MS))
  }

  expireIfDue()

  async function logIn(credentials: PortalLoginRequest): Promise<void> {
    const { session, serverNow } = await logInRequest(credentials)

    // La vida de la sesion se mide contra la hora del SERVIDOR (cabecera `Date`),
    // no contra el reloj del dispositivo: un movil con la hora mal puesta no
    // puede cerrarla al instante ni mantenerla abierta de mas. `expiresAt` se
    // guarda ya en el reloj de este dispositivo, para que el temporizador y la
    // recarga de la pestana sigan sin depender de el. Sin cabecera legible, se
    // usa tal cual la caducidad que manda el servidor.
    const lifetimeMs =
      serverNow === null ? null : Date.parse(session.expires_at) - serverNow.getTime()
    const localExpiry =
      lifetimeMs !== null && Number.isFinite(lifetimeMs)
        ? new Date(Date.now() + lifetimeMs).toISOString()
        : session.expires_at

    token.value = session.token
    expiresAt.value = localExpiry
    employee.value = session.employee
    endedReason.value = null
    writeStored({ token: session.token, expiresAt: localExpiry, employee: session.employee })
    expireIfDue()
  }

  /** Solo olvida la sesion en este dispositivo. Para la caducidad, no para el boton «Salir». */
  function signOutLocally(): void {
    clear()
  }

  /**
   * Boton «Salir»: avisa al servidor y despues borra lo local, siempre. Ningun
   * desenlace de la llamada -ni el 401 de una sesion ya cerrada, ni el 403, ni
   * el 429 de nginx, ni un fallo de red, ni el tiempo maximo- impide salir.
   */
  async function signOut(): Promise<void> {
    const current = token.value

    // La sesion local se cierra ANTES de esperar al servidor: un `401` de otra
    // peticion que llegue mientras tanto ya no encuentra sesion que caducar
    // (`expire()` no hace nada sin token) ni la presenta como «sesion caducada».
    clear()

    if (current !== null) {
      const controller = new AbortController()
      const timer = setTimeout(() => controller.abort(), LOGOUT_TIMEOUT_MS)

      try {
        await logOutRequest(current, controller.signal)
      } catch {
        // Da igual por que: la sesion local se borra de todos modos.
      } finally {
        clearTimeout(timer)
      }
    }
  }

  return {
    token,
    expiresAt,
    employee,
    endedReason,
    isAuthenticated,
    logIn,
    signOut,
    signOutLocally,
    expire,
    expireIfDue,
    clear,
  }
})
