// Interceptor global del `403 password-change-required` (RF-ID-10).
//
// Con una contrasena temporal, el token de la sesion nace con el unico ambito
// `password:change`: CUALQUIER peticion salvo `/auth/me`, `/auth/logout` y
// `/auth/password` responde ese `403`, incluida la carga del propio marco. Si
// cada pantalla tuviera que reconocerlo, la primera que se olvidara enseñaria
// «no tienes permiso» a quien solo tiene que cambiar su contrasena.
//
// Se engancha a `fetch` y no al cliente de consultas porque no todas las
// llamadas pasan por TanStack Query (las de los dialogos y la guarda del
// router, no). El cuerpo se lee de un `clone()`: quien llamo recibe la respuesta
// intacta y su error normal. `onRequired` se avisa UNA vez por cada 403 y debe
// ser idempotente: aqui no hay reintentos, asi que no hay bucle posible.
import { PASSWORD_CHANGE_REQUIRED_TYPE } from './problems'

export function installPasswordChangeInterceptor(onRequired: () => void): () => void {
  const nativeFetch = globalThis.fetch

  globalThis.fetch = async (input, init) => {
    const response = await nativeFetch.call(globalThis, input, init)

    if (response.status === 403) {
      try {
        const body: unknown = await response.clone().json()

        if (
          typeof body === 'object' &&
          body !== null &&
          (body as { type?: unknown }).type === PASSWORD_CHANGE_REQUIRED_TYPE
        ) {
          onRequired()
        }
      } catch {
        // Un 403 sin cuerpo JSON no es este problema.
      }
    }

    return response
  }

  return () => {
    globalThis.fetch = nativeFetch
  }
}
