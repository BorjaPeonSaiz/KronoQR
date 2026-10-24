import type { RouteRecordRaw } from 'vue-router'
import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '@/features/login/LoginView.vue'
import AppShellView from '@/shared/ui/AppShellView.vue'

// Las rutas se escriben en ingles como el resto del codigo (CLAUDE.md): una URL
// no es un texto de usuario y no se traduce.
declare module 'vue-router' {
  interface RouteMeta {
    /** Accesible sin sesion. Solo el acceso. */
    public?: boolean
    /** Clave de i18n con el nombre de la pantalla, para el titulo de la pestana. */
    titleKey?: string
  }
}

// Solo el acceso y el marco van en el chunk de entrada. Cada pantalla se baja
// cuando se pide (8.8): se abre con datos moviles personales, y quien
// entra por el acceso no necesita todavia el codigo de las otras.
export const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: LoginView,
    meta: { public: true, titleKey: 'login.heading' },
  },
  {
    // El marco autenticado. Sin nombre a proposito: nombrar un padre que tiene
    // un hijo con ruta vacia produce un nombre que nunca casa.
    path: '/',
    component: AppShellView,
    children: [
      { path: '', name: 'home', redirect: { name: 'my-records' } },
      {
        // Tres pantallas y ninguna mas (doc 02 §11). Esta es la que
        // existe por obligacion legal: el registro de jornada propio (RL-05).
        path: 'records',
        name: 'my-records',
        component: () => import('@/features/my-records/MyRecordsView.vue'),
        meta: { titleKey: 'myRecords.title' },
      },
      {
        path: 'export',
        name: 'my-export',
        component: () => import('@/features/my-export/MyExportView.vue'),
        meta: { titleKey: 'myExport.title' },
      },
      {
        path: ':pathMatch(.*)*',
        name: 'not-found',
        component: () => import('@kronoqr/web-kit/components/NotFoundView.vue'),
        meta: { titleKey: 'notFound.heading' },
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
