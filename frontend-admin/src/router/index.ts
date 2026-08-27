import type { RouteRecordRaw } from 'vue-router'
import { createRouter, createWebHistory } from 'vue-router'
import {
  ATTENDANCE_READ,
  CREDENTIALS_MANAGE,
  EMPLOYEES_MANAGE,
  REPORTS_LEGAL,
} from '@/features/auth/abilities'
import LoginView from '@/features/auth/LoginView.vue'
import CredentialBoardView from '@/features/credentials/CredentialBoardView.vue'
import EmployeeDetailView from '@/features/employees/EmployeeDetailView.vue'
import EmployeeListView from '@/features/employees/EmployeeListView.vue'
import LegalExportView from '@/features/reports/LegalExportView.vue'
import EmployeeWorkDaysView from '@/features/workdays/EmployeeWorkDaysView.vue'
import AppShellView from '@/shared/ui/AppShellView.vue'
import ForbiddenView from '@/shared/ui/ForbiddenView.vue'
import NotFoundView from '@/shared/ui/NotFoundView.vue'

// Las rutas se escriben en ingles como el resto del codigo (CLAUDE.md): una URL
// no es un texto de usuario y no se traduce, asi que tenerla en un idioma y la
// interfaz en otro seria peor que tenerla en el idioma del codigo.
declare module 'vue-router' {
  interface RouteMeta {
    /** Accesible sin sesion. Solo el acceso. */
    public?: boolean
    /** Ambito del token que exige la pantalla (doc 02 §7.3). */
    ability?: string
  }
}

export const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: LoginView,
    meta: { public: true },
  },
  {
    // El marco autenticado. Sin nombre a proposito: nombrar un padre que tiene
    // un hijo con ruta vacia produce un nombre que nunca casa.
    path: '/',
    component: AppShellView,
    children: [
      { path: '', name: 'home', redirect: { name: 'employees' } },
      {
        path: 'employees',
        name: 'employees',
        component: EmployeeListView,
        meta: { ability: EMPLOYEES_MANAGE },
      },
      {
        path: 'employees/:uuid',
        name: 'employee',
        component: EmployeeDetailView,
        props: true,
        meta: { ability: EMPLOYEES_MANAGE },
      },
      {
        // El registro horario de una persona (RF-PA-03). Cuelga de la ficha y no
        // del menu porque no existe «el detalle de jornada» a secas: siempre es
        // el de alguien, y sin esa persona la pantalla no significa nada.
        //
        // Ambito `attendance:read`, que es el que declara el contrato para el
        // endpoint: el estrecho de solo lectura. La policy del servidor es la
        // que autoriza de verdad (regla dura 18).
        path: 'employees/:uuid/workdays',
        name: 'employee-workdays',
        component: EmployeeWorkDaysView,
        props: true,
        meta: { ability: ATTENDANCE_READ },
      },
      {
        path: 'credentials',
        name: 'credentials',
        component: CredentialBoardView,
        meta: { ability: CREDENTIALS_MANAGE },
      },
      {
        // La exportacion para la Inspeccion (RF-IN-05). Ambito `reports:legal`:
        // el estrecho, que es el unico que lleva el `auditor`. La policy del
        // servidor es la que autoriza de verdad (regla dura 18).
        path: 'reports/legal-export',
        name: 'legal-export',
        component: LegalExportView,
        meta: { ability: REPORTS_LEGAL },
      },
      { path: 'forbidden', name: 'forbidden', component: ForbiddenView },
      { path: ':pathMatch(.*)*', name: 'not-found', component: NotFoundView },
    ],
  },
]

export function createAppRouter(): ReturnType<typeof createRouter> {
  return createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes,
  })
}
