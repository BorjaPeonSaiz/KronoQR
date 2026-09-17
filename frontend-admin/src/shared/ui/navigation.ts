// Las secciones del panel, declaradas UNA SOLA VEZ (deuda tecnica anotada en
// `HANDOFF.md` desde la tarea 3.3: `AppShellView.vue` y `router/guards.ts`
// llevaban cada una su propia lista, y una sola pantalla nueva obligaba a
// tocar dos ficheros que tenian que acordarse de decir lo mismo).
//
// Este modulo es la unica fuente: el NOMBRE de la ruta, la CLAVE i18n de su
// etiqueta y los AMBITOS del token que la alcanzan (en O: basta con uno). Lo
// consumen dos sitios con necesidades distintas:
//
//  - `AppShellView.vue` pinta el menu completo, en ESTE orden, filtrado por lo
//    que la sesion alcanza.
//  - `router/guards.ts` usa `FALLBACK_SECTIONS`, un SUBCONJUNTO en un orden
//    PROPIO (el de prioridad de aterrizaje, no el del menu), para decidir a
//    donde mandar a quien pide una pantalla que no alcanza.
//
// La autorizacion real esta en la policy de cada endpoint (regla dura 18):
// esto solo evita frustracion, nunca protege un dato.
import {
  ATTENDANCE_READ,
  CREDENTIALS_MANAGE,
  DIAGNOSTICS_MANAGE,
  EMPLOYEES_MANAGE,
  INCIDENTS_MANAGE,
  LICENSE_MANAGE,
  REPORTS_LEGAL,
  REPORTS_MANAGE,
  SETTINGS_MANAGE,
  SUPPORT_MANAGE,
} from '@/features/auth/abilities'

export interface NavigationSection {
  readonly name: string
  /** Clave de `locales/{es,en}.json`, bajo `app.nav.*`. */
  readonly labelKey: string
  /** En O: basta con que la sesion lleve uno de los ambitos listados. */
  readonly abilities: readonly string[]
}

/** El menu completo, en el orden en que se ofrece (`AppShellView.vue`). */
export const NAVIGATION_SECTIONS: readonly NavigationSection[] = [
  { name: 'employees', labelKey: 'app.nav.employees', abilities: [EMPLOYEES_MANAGE] },
  { name: 'live', labelKey: 'app.nav.live', abilities: [ATTENDANCE_READ] },
  { name: 'incidents', labelKey: 'app.nav.incidents', abilities: [INCIDENTS_MANAGE] },
  {
    // La vista de cumplimiento (RF-PA-06, tarea 3.4): despues de «Incidencias»
    // a proposito, es la primera pantalla de trabajo de un responsable tras la
    // bandeja. Mismo ambito que «Presencia»: leer el registro con una regla
    // legal encima sigue siendo leer el registro.
    name: 'compliance',
    labelKey: 'app.nav.compliance',
    abilities: [ATTENDANCE_READ],
  },
  { name: 'credentials', labelKey: 'app.nav.credentials', abilities: [CREDENTIALS_MANAGE] },
  { name: 'reports', labelKey: 'app.nav.reports', abilities: [REPORTS_MANAGE] },
  { name: 'legal-export', labelKey: 'app.nav.legalExport', abilities: [REPORTS_LEGAL] },
  {
    // El perfil de cumplimiento (RF-PD-07, tarea 5.2): los umbrales LEGALES del
    // centro. Renombrado de «Cumplimiento» a «Perfil de cumplimiento» en la
    // tarea 3.4, para que el nombre no colisione con la vista de hallazgos de
    // arriba: las dos cuentan cosas distintas -una es la ficha del convenio,
    // la otra es quien lo incumple- y compartir etiqueta las confundiria.
    name: 'compliance-profile',
    labelKey: 'app.nav.complianceProfile',
    abilities: [SETTINGS_MANAGE],
  },
  {
    // Umbrales operativos e idiomas (RF-PD-01, tarea 5.13). Mismo ambito
    // que «Perfil de cumplimiento», «Quioscos» y «Marca»: las cuatro son la
    // misma potestad de administrador de instalacion.
    name: 'operational-settings',
    labelKey: 'app.nav.operationalSettings',
    abilities: [SETTINGS_MANAGE],
  },
  { name: 'devices', labelKey: 'app.nav.devices', abilities: [SETTINGS_MANAGE] },
  { name: 'branding', labelKey: 'app.nav.branding', abilities: [SETTINGS_MANAGE] },
  { name: 'license', labelKey: 'app.nav.license', abilities: [LICENSE_MANAGE] },
  {
    // Soporte (RF-PD-09, RF-PD-11, tarea 5.9): la alcanza quien lleva
    // `support:*` -para conceder y revocar accesos- **o** `diagnostics:*`
    // -un token de soporte con ese alcance tambien puede generar el
    // paquete anonimizado de su propia intervencion-. Ninguno de los dos lo
    // lleva un rol distinto de `admin` (doc 02 §7.3).
    name: 'support',
    labelKey: 'app.nav.support',
    abilities: [SUPPORT_MANAGE, DIAGNOSTICS_MANAGE],
  },
  {
    // Historico de errores agrupado por huella (RF-PD-15, tarea 5.12): el
    // mismo ambito que la generacion del paquete de diagnostico.
    name: 'errors',
    labelKey: 'app.nav.errors',
    abilities: [DIAGNOSTICS_MANAGE],
  },
]

/** La seccion `name`, o lanza: un nombre que no existe en `NAVIGATION_SECTIONS` es un error de programacion. */
function section(name: string): NavigationSection {
  const found = NAVIGATION_SECTIONS.find((candidate) => candidate.name === name)

  if (found === undefined) {
    throw new Error(`navigation.ts: no existe la seccion «${name}»`)
  }

  return found
}

/**
 * Destinos de reserva de `router/guards.ts`, en SU PROPIO orden de prioridad
 * -no el del menu-: la primera seccion de esta lista que la sesion alcanza es
 * a donde va quien pide una pantalla fuera de su ambito.
 *
 * Es un SUBCONJUNTO deliberado y no las catorce secciones: basta con que cubra a
 * los cuatro roles de gestion (`admin`, `rrhh`, `responsable_departamento`,
 * `auditor`), y una lista mas larga no cambiaria a donde aterriza ninguno de
 * los cuatro, solo tardaria mas en decidirlo.
 */
export const FALLBACK_SECTIONS: readonly NavigationSection[] = [
  section('employees'),
  section('credentials'),
  // La ultima, y eso importa: es la unica seccion que alcanza un `auditor`, que
  // no tiene ni plantilla ni credenciales. Sin ella, entrar con ese rol acababa
  // en «sin permiso» teniendo permiso para algo.
  section('legal-export'),
  // Despues de la exportacion a proposito: el `auditor` tambien lee la
  // presencia, pero su pantalla de partida sigue siendo la Inspeccion. Es la
  // primera seccion que alcanza un `responsable_departamento`, que no tiene
  // plantilla, ni credenciales, ni exportacion legal (RF-ID-03).
  section('live'),
  // Tambien alcanza a un `responsable_departamento` (RF-PA-05), pero despues de
  // la presencia: ver quien esta dentro ahora mismo es la foto, trabajar la
  // bandeja es la tarea, y quien entra sin la presencia a su alcance
  // (`admin`/`rrhh` sin `attendance:read` en un despliegue que se lo quitara)
  // sigue llegando aqui igualmente.
  section('incidents'),
]
