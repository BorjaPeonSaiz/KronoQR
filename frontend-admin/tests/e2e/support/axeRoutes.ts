// Una pasada de axe por CADA ruta del panel (V21-7-QA-1, R7-QA-01, V7-QA-1).
//
// La clave es el `name` de la ruta en `src/router/index.ts`. Dos pruebas viven
// de este mismo dataset:
//
// - `tests/e2e/accessibility-routes.spec.ts` visita cada `path`, espera su `h1`
//   y pasa axe con `best-practice`;
// - `tests/unit/axeRouteCoverage.spec.ts` exige que las claves sean EXACTAMENTE
//   las rutas visitables del router y que cada `path` resuelva a su clave.
//
// Asi, una pantalla nueva sin su pasada rompe la CI en vez de esperar a que una
// verificacion la eche en falta (en cada version aparecian pantallas sin axe).
//
// Es solo datos: lo importa tambien Vitest, que no puede cargar Playwright.

import { EMPLOYEE_UUID } from './admin'

/**
 * Como se llega a la pantalla. `admin` entra con `ADMIN_USER`, que lleva todos
 * los ambitos; `public` no inicia sesion; `setup` simula una instalacion sin
 * configurar (`stubOnboardingApi`), la unica forma de que `/setup` se abra.
 */
export type AxeRouteAccess = 'admin' | 'public' | 'setup'

export interface AxeRoutePass {
  readonly path: string
  readonly access: AxeRouteAccess
  /** El `h1` que dice que la pantalla ya esta pintada y no en carga. */
  readonly heading: string
  /** Requisitos de la pantalla, para la matriz de trazabilidad (§9.6). */
  readonly requirements: readonly `@${string}`[]
}

export const AXE_ROUTE_PASSES: Readonly<Record<string, AxeRoutePass>> = {
  login: {
    path: '/login',
    access: 'public',
    heading: 'Acceso al panel de gestión',
    requirements: ['@RF-ID-01'],
  },
  setup: {
    path: '/setup',
    access: 'setup',
    heading: 'Puesta en marcha',
    requirements: ['@RF-PD-03'],
  },
  employees: {
    path: '/employees',
    access: 'admin',
    heading: 'Plantilla',
    requirements: ['@RF-GP-01'],
  },
  employee: {
    path: `/employees/${EMPLOYEE_UUID}`,
    access: 'admin',
    heading: 'Ficha del empleado',
    requirements: ['@RF-GP-01', '@RF-GP-02'],
  },
  absences: {
    path: '/absences',
    access: 'admin',
    heading: 'Ausencias',
    requirements: ['@RF-GP-04'],
  },
  'employee-workdays': {
    path: `/employees/${EMPLOYEE_UUID}/workdays`,
    access: 'admin',
    heading: 'Registro horario',
    requirements: ['@RF-PA-03'],
  },
  live: {
    path: '/live',
    access: 'admin',
    heading: 'Presencia en vivo',
    requirements: ['@RF-PA-01'],
  },
  credentials: {
    path: '/credentials',
    access: 'admin',
    heading: 'Credenciales',
    requirements: ['@RF-QR-07'],
  },
  incidents: {
    path: '/incidents',
    access: 'admin',
    heading: 'Bandeja de incidencias',
    requirements: ['@RF-PA-05'],
  },
  compliance: {
    path: '/compliance',
    access: 'admin',
    heading: 'Cumplimiento',
    requirements: ['@RF-PA-06'],
  },
  reports: {
    path: '/reports',
    access: 'admin',
    heading: 'Informe de horas por periodo',
    requirements: ['@RF-IN-01'],
  },
  'payroll-export': {
    path: '/reports/payroll',
    access: 'admin',
    heading: 'Salida a nómina',
    requirements: ['@RF-IN-07'],
  },
  'adoption-dashboard': {
    path: '/reports/adoption',
    access: 'admin',
    heading: 'Impacto y adopción',
    requirements: ['@RF-IN-08'],
  },
  'legal-export': {
    path: '/reports/legal-export',
    access: 'admin',
    heading: 'Exportación para la Inspección de Trabajo',
    requirements: ['@RF-IN-05'],
  },
  'compliance-profile': {
    path: '/compliance-profile',
    access: 'admin',
    heading: 'Perfil de cumplimiento',
    requirements: ['@RF-PD-07'],
  },
  'operational-settings': {
    path: '/settings',
    access: 'admin',
    heading: 'Ajustes operativos',
    requirements: ['@RF-PD-01'],
  },
  devices: {
    path: '/devices',
    access: 'admin',
    heading: 'Quioscos',
    requirements: ['@RF-PD-06', '@RF-PA-07'],
  },
  branding: {
    path: '/branding',
    access: 'admin',
    heading: 'Marca',
    requirements: ['@RF-PD-08'],
  },
  license: {
    path: '/license',
    access: 'admin',
    heading: 'Licencia',
    requirements: ['@RF-PD-04'],
  },
  support: {
    path: '/support',
    access: 'admin',
    heading: 'Soporte',
    requirements: ['@RF-PD-09', '@RF-PD-11'],
  },
  errors: {
    path: '/errors',
    access: 'admin',
    heading: 'Errores',
    requirements: ['@RF-PD-15'],
  },
  departments: {
    path: '/departments',
    access: 'admin',
    heading: 'Departamentos',
    requirements: ['@RF-ID-03'],
  },
  accounts: {
    path: '/accounts',
    access: 'admin',
    heading: 'Cuentas de gestión',
    requirements: ['@RF-ID-10'],
  },
  'change-password': {
    path: '/account/password',
    access: 'admin',
    heading: 'Cambiar mi contraseña',
    requirements: ['@RF-ID-10'],
  },
  forbidden: {
    path: '/forbidden',
    access: 'admin',
    heading: 'Sin permiso para esta sección',
    requirements: ['@RF-ID-02'],
  },
  'not-found': {
    path: '/esta-pantalla-no-existe',
    access: 'admin',
    heading: 'Esta página no existe',
    requirements: ['@RF-ID-02'],
  },
}
