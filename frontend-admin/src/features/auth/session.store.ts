// Estado de la sesion de gestion.
//
// Que se guarda y donde, que es la decision que importa:
//
//  - El token y su caducidad van a `sessionStorage`, no a `localStorage`: la
//    sesion de gestion es corta (doc 02 §7.3) y muere con la pestaña. Un panel
//    de RRHH abierto en el ordenador compartido de recepcion no puede seguir
//    dentro mañana.
//  - El usuario NO se persiste: se vuelve a pedir a `/auth/me` al arrancar, que
//    ademas comprueba que el token sigue valiendo.
//  - **Aqui no entra ningun PIN, nunca.** El PIN en claro vive en el estado
//    efimero del dialogo que lo muestra y desaparece al cerrarlo (RF-ID-09).
import { isApiError } from '@kronoqr/web-kit/http'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { LoginRequest, ManagementUser, UserRole } from '@/shared/api/types'
import { hasAbility } from './abilities'
import { fetchCurrentUser, logIn as logInRequest, logOut as logOutRequest } from './auth.api'

const STORAGE_KEY = 'kronoqr.admin.session'

/** Lo unico que sobrevive a una recarga. Sin datos personales. */
interface StoredSession {
  token: string
  expiresAt: string
}

function readStored(): StoredSession | null {
  try {
    const raw = globalThis.sessionStorage?.getItem(STORAGE_KEY) ?? null

    if (raw === null) {
      return null
    }

    const parsed: unknown = JSON.parse(raw)

    if (
      typeof parsed === 'object' &&
      parsed !== null &&
      typeof (parsed as StoredSession).token === 'string' &&
      typeof (parsed as StoredSession).expiresAt === 'string'
    ) {
      return parsed as StoredSession
    }
  } catch {
    // Un almacenamiento ilegible es una sesion que no existe, no un fallo que
    // deba impedir entrar.
  }

  return null
}

function writeStored(value: StoredSession | null): void {
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

function expired(expiresAt: string | null, now: Date): boolean {
  if (expiresAt === null) {
    return false
  }

  const instant = new Date(expiresAt).getTime()

  return Number.isFinite(instant) && instant <= now.getTime()
}

/** Situacion de la sesion mientras el panel decide si hay que pedir acceso. */
export type SessionStatus = 'unknown' | 'authenticated' | 'anonymous'

export const useSessionStore = defineStore('session', () => {
  const stored = readStored()

  const token = ref<string | null>(stored?.token ?? null)
  const expiresAt = ref<string | null>(stored?.expiresAt ?? null)
  const user = ref<ManagementUser | null>(null)
  const status = ref<SessionStatus>(stored === null ? 'anonymous' : 'unknown')

  const isAuthenticated = computed(() => token.value !== null && status.value !== 'anonymous')
  const abilities = computed<readonly string[]>(() => user.value?.abilities ?? [])
  const roles = computed<readonly UserRole[]>(() => user.value?.roles ?? [])
  const displayName = computed(() => user.value?.name ?? '')

  /** Si la interfaz debe ofrecer una accion que exige este ambito. */
  function can(ability: string): boolean {
    return hasAbility(abilities.value, ability)
  }

  function clear(): void {
    token.value = null
    expiresAt.value = null
    user.value = null
    status.value = 'anonymous'
    writeStored(null)
  }

  async function logIn(credentials: LoginRequest): Promise<void> {
    const session = await logInRequest(credentials)

    token.value = session.token
    expiresAt.value = session.expires_at
    user.value = session.user
    status.value = 'authenticated'
    writeStored({ token: session.token, expiresAt: session.expires_at })
  }

  /**
   * Recupera la sesion al arrancar el panel. Un token caducado o rechazado no es
   * un error que enseñar: es volver a la pantalla de acceso.
   */
  async function restore(now: Date = new Date()): Promise<void> {
    if (token.value === null || expired(expiresAt.value, now)) {
      clear()

      return
    }

    try {
      user.value = await fetchCurrentUser()
      status.value = 'authenticated'
    } catch (error) {
      if (isApiError(error) && error.status === 401) {
        clear()

        return
      }

      // Un corte de red al arrancar no invalida el token: se deja la sesion como
      // esta y la pantalla que toque enseñara su propio error de red.
      status.value = 'unknown'
    }
  }

  async function logOut(): Promise<void> {
    try {
      await logOutRequest()
    } catch {
      // Un token ya revocado responde 401, que es exactamente «ya no hay
      // sesion». Se limpia igual.
    } finally {
      clear()
    }
  }

  return {
    token,
    expiresAt,
    user,
    status,
    isAuthenticated,
    abilities,
    roles,
    displayName,
    can,
    clear,
    logIn,
    logOut,
    restore,
  }
})
