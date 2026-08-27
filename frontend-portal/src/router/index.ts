import NotFoundView from '@kronoqr/web-kit/components/NotFoundView.vue'
import type { RouteRecordRaw } from 'vue-router'
import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '@/features/login/LoginView.vue'
import MyExportView from '@/features/my-export/MyExportView.vue'
import MyRecordsView from '@/features/my-records/MyRecordsView.vue'
import AppShellView from '@/shared/ui/AppShellView.vue'

// Las rutas se escriben en ingles como el resto del codigo (CLAUDE.md): una URL
// no es un texto de usuario y no se traduce.
declare module 'vue-router' {
  interface RouteMeta {
    /** Accesible sin sesion. Solo el acceso. */
    public?: boolean
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
      { path: '', name: 'home', redirect: { name: 'my-records' } },
      {
        // Tres pantallas y ninguna mas (doc 02 §11, tarea 1.11). Esta es la que
        // existe por obligacion legal: el registro de jornada propio (RL-05).
        path: 'records',
        name: 'my-records',
        component: MyRecordsView,
      },
      {
        path: 'export',
        name: 'my-export',
        component: MyExportView,
      },
      {
        path: ':pathMatch(.*)*',
        name: 'not-found',
        component: NotFoundView,
        // El portal es de consulta, con dos pantallas propias: el rescate
        // vuelve a mi registro, que es la pantalla de partida (`@kronoqr/web-kit`,
        // ADR-036).
        props: { backToRouteName: 'my-records', backToLabelKey: 'notFound.backToRecords' },
      },
    ],
  },
]

export function createAppRouter(): ReturnType<typeof createRouter> {
  return createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes,
  })
}
