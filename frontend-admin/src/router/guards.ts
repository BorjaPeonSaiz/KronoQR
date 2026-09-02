// Guarda de rutas.
//
// Hace TRES cosas y ninguna es seguridad:
//  0. Si la instalacion todavia no se ha puesto en marcha (RF-PD-03), manda al
//     asistente sea cual sea la ruta pedida —con una unica excepcion, el
//     acceso (`/login`), que es la via de escape de quien recarga sin sesion a
//     mitad del asistente—; y en cuanto el asistente se cierra, deja de poder
//     visitarse (es de un solo uso).
//  1. Sin sesion valida, manda al acceso y se acuerda de a donde iba.
//  2. Si la pantalla exige un ambito que el token no tiene, lleva a la primera
//     seccion que si puede usar, y solo si no hay ninguna enseña «sin permiso».
//
// La autorizacion real la aplica el servidor en cada endpoint (regla dura 18).
// Aqui se evita la frustracion de entrar a una pantalla que devolveria 403.
import type { Router } from 'vue-router'
import {
  ATTENDANCE_READ,
  CREDENTIALS_MANAGE,
  EMPLOYEES_MANAGE,
  INCIDENTS_MANAGE,
  REPORTS_LEGAL,
} from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import { useSetupStore } from '@/features/onboarding/setup.store'

/** Secciones navegables, en el orden en el que se ofrecen. */
const SECTIONS: readonly { name: string; ability: string }[] = [
  { name: 'employees', ability: EMPLOYEES_MANAGE },
  { name: 'credentials', ability: CREDENTIALS_MANAGE },
  // La ultima, y eso importa: es la unica seccion que alcanza un `auditor`, que
  // no tiene ni plantilla ni credenciales. Sin ella, entrar con ese rol acababa
  // en «sin permiso» teniendo permiso para algo.
  { name: 'legal-export', ability: REPORTS_LEGAL },
  // Despues de la exportacion a proposito: el `auditor` tambien lee la
  // presencia, pero su pantalla de partida sigue siendo la Inspeccion. Es la
  // primera seccion que alcanza un `responsable_departamento`, que no tiene
  // plantilla, ni credenciales, ni exportacion legal (RF-ID-03).
  { name: 'live', ability: ATTENDANCE_READ },
  // Tambien alcanza a un `responsable_departamento` (RF-PA-05), pero despues de
  // la presencia: ver quien esta dentro ahora mismo es la foto, trabajar la
  // bandeja es la tarea, y quien entra sin la presencia a su alcance
  // (`admin`/`rrhh` sin `attendance:read` en un despliegue que se lo quitara)
  // sigue llegando aqui igualmente.
  { name: 'incidents', ability: INCIDENTS_MANAGE },
]

export function registerAuthGuard(router: Router): void {
  router.beforeEach(async (to) => {
    const setup = useSetupStore()

    // Se pide UNA vez por carga de la aplicacion (como `session.restore`), no
    // en cada navegacion: el endpoint que toque —`GET /setup/status`, publico,
    // o `GET /setup/steps`, autenticado, segun decida `setup.store`— tiene su
    // propio limite de peticiones (`PRODUCT_SETUP_RATE_LIMIT`). Si el aviso
    // falla por red, `loaded` se queda en `false` y la instalacion sigue
    // funcionando con normalidad: un corte pasajero de ese endpoint no puede
    // dejar el panel entero inalcanzable.
    if (!setup.loaded) {
      await setup.load()
    }

    if (to.name === 'setup') {
      // El asistente es de un solo uso: cerrado, no vuelve a estar accesible.
      return setup.loaded && !setup.available ? { name: 'login' } : true
    }

    // Via de escape: el asistente manda TODO a `/setup` mientras siga
    // abierto, salvo el acceso. Sin esta excepcion, quien recarga sin sesion
    // —el administrador ya se creo pero el segundo factor no llego a
    // confirmarse, o el token de la pestaña caduco— queda atrapado en el paso
    // del administrador (`AdministratorStep`, caso especial de `setup.store`
    // sin `steps`): su `POST /setup/administrator` responde `409` y remite a
    // `/auth/login` (contrato), pero esa ruta seguiria bloqueada por la regla
    // de abajo. En cuanto autentica, la siguiente navegacion vuelve a mandar a
    // `/setup` mientras siga abierto —esta excepcion no reabre nada, solo deja
    // pasar.
    if (setup.loaded && setup.available && to.name !== 'login') {
      return { name: 'setup' }
    }

    const session = useSessionStore()

    if (to.meta.public === true) {
      return true
    }

    if (session.status === 'unknown') {
      await session.restore()
    }

    if (!session.isAuthenticated) {
      return { name: 'login', query: { redirect: to.fullPath } }
    }

    const ability = to.meta.ability

    if (ability === undefined || session.can(ability)) {
      return true
    }

    const fallback = SECTIONS.find((section) => session.can(section.ability))

    return fallback === undefined ? { name: 'forbidden' } : { name: fallback.name }
  })
}
