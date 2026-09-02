// El asistente de puesta en marcha (RF-PD-03, tarea 5.5). Las formas salen del
// contrato; aqui no se inventa ninguna.
//
// `GET /setup/status` es PUBLICO (se llama antes de que exista ninguna cuenta),
// y por eso no lleva `Authorization`: se pide con `anonymous: true` igual que
// `/auth/login`. **Nunca trae `steps`**: la revision de la 5.5 lo movio a
// `GET /setup/steps`, que exige sesion de administrador (`settings:*`) porque
// el detalle de que pasos faltan es un inventario de la postura de la
// instalacion, no algo que decidir a donde lleva el navegador. El resto de
// rutas de este fichero exigen la sesion que deja `POST /setup/administrator`
// + el segundo factor, y el cliente HTTP base ya la añade sola en cuanto existe.
import { requestJson } from '@kronoqr/web-kit/http'
import type {
  CreateFirstAdministratorRequest,
  CreateInstallationSiteRequest,
  SetupCompletion,
  SetupStatus,
  SetupStep,
  Site,
  TwoFactorChallenge,
} from '@/shared/api/types'

/** Solo `available`/`completed_at`: lo que hace falta para decidir asistente-o-acceso. */
export function fetchSetupStatus(): Promise<SetupStatus> {
  return requestJson<SetupStatus>('/api/v1/setup/status', { anonymous: true })
}

/**
 * Los ocho pasos y su estado, para que el asistente sea reanudable
 * (`GET /setup/steps`). Autenticada: sin sesion responde `401`, y por eso
 * `setup.store` solo la llama en cuanto hay una (tras confirmar el segundo
 * factor del primer administrador, o al arrancar con un token ya guardado).
 */
export function fetchSetupSteps(): Promise<SetupStatus> {
  return requestJson<SetupStatus>('/api/v1/setup/steps')
}

/**
 * La primera cuenta de gestion. No devuelve sesion: devuelve el mismo reto de
 * segundo factor que el `202` de `/auth/login` (RS-06), asi que se abre con la
 * misma pantalla de alta del TOTP.
 */
export function createFirstAdministrator(
  body: CreateFirstAdministratorRequest,
): Promise<TwoFactorChallenge> {
  return requestJson<TwoFactorChallenge>('/api/v1/setup/administrator', {
    method: 'POST',
    anonymous: true,
    body,
  })
}

/** El unico centro de la instalacion (ADR-040). Solo se puede llamar una vez. */
export function createInstallationSite(body: CreateInstallationSiteRequest): Promise<Site> {
  return requestJson<Site>('/api/v1/setup/site', { method: 'POST', body })
}

/**
 * Marca un paso hecho u omitido. `administrator` y `site` no admiten esta
 * llamada (se deducen del dato, nunca se marcan): enviarlos aqui es un error
 * de programacion, no una posibilidad de la interfaz.
 */
export function recordSetupStep(
  step: Exclude<SetupStep, 'administrator' | 'site'>,
  state: 'completed' | 'skipped',
): Promise<SetupStatus> {
  return requestJson<SetupStatus>(`/api/v1/setup/steps/${step}`, { method: 'PUT', body: { state } })
}

/** Cierra el asistente para siempre y devuelve el resumen accionable del primer dia. */
export function completeSetup(): Promise<SetupCompletion> {
  return requestJson<SetupCompletion>('/api/v1/setup/complete', { method: 'POST' })
}
