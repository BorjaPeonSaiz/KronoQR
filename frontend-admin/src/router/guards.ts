// Guarda de rutas.
//
// Hace dos cosas y ninguna es seguridad:
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
  REPORTS_LEGAL,
} from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'

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
]

export function registerAuthGuard(router: Router): void {
  router.beforeEach(async (to) => {
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
