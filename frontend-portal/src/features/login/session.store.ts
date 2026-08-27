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
//  - **No hay endpoint de cierre de sesion para el portal en el contrato**: el
//    unico `POST /api/v1/auth/logout` exige `managementToken` (§/api/v1/auth),
//    no `employeeToken`. `signOutLocally` por eso SOLO olvida la sesion en
//    este dispositivo; el token sigue siendo valido en el servidor hasta su
//    caducidad natural (maximo 2 h, PortalSession.expires_at). Revocarlo de
//    verdad -pensando en el ordenador compartido del centro- necesitaria un
//    `POST /api/v1/me/logout` nuevo en `docs/api/openapi.yaml`, que hoy no
//    existe: no se ha improvisado aqui, se deja anotado para pedirlo.
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { PortalEmployee, PortalLoginRequest } from '@/shared/api/types'
import { logInToPortal as logInRequest } from './login.api'

const STORAGE_KEY = 'kronoqr.portal.session'

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

function isExpired(expiresAt: string | null, now: Date): boolean {
  if (expiresAt === null) {
    return false
  }

  const instant = new Date(expiresAt).getTime()

  return Number.isFinite(instant) && instant <= now.getTime()
}

export const useSessionStore = defineStore('portal-session', () => {
  const stored = readStored()

  const token = ref<string | null>(stored?.token ?? null)
  const expiresAt = ref<string | null>(stored?.expiresAt ?? null)
  const employee = ref<PortalEmployee | null>(stored?.employee ?? null)

  /**
   * Valida solo la caducidad guardada. No hay `GET /me` que confirme el token
   * contra el servidor (el portal no tiene ese endpoint): un token revocado o
   * ya vencido en el servidor se descubre en la primera peticion real, y el
   * gestor de `401` de `@kronoqr/web-kit/http` cierra la sesion entonces.
   */
  const isAuthenticated = computed(
    () => token.value !== null && !isExpired(expiresAt.value, new Date()),
  )

  function clear(): void {
    token.value = null
    expiresAt.value = null
    employee.value = null
    writeStored(null)
  }

  async function logIn(credentials: PortalLoginRequest): Promise<void> {
    const session = await logInRequest(credentials)

    token.value = session.token
    expiresAt.value = session.expires_at
    employee.value = session.employee
    writeStored({ token: session.token, expiresAt: session.expires_at, employee: session.employee })
  }

  /** Ver la nota de cabecera: solo olvida la sesion en este dispositivo. */
  function signOutLocally(): void {
    clear()
  }

  return { token, expiresAt, employee, isAuthenticated, logIn, signOutLocally, clear }
})
