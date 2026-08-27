// Cache de consultas (TanStack Query).
//
// Con 500 empleados y meses de historico, cada pantalla que vuelve a pedir todo
// al montarse es una pantalla lenta. La cache la evita; lo que NO hace es
// reintentar cualquier cosa: un 403 o un 409 reintentado es ruido en el
// servidor y una espera inutil para quien mira. Solo se reintenta el corte de
// red, que es lo unico que puede arreglarse solo.
import { QueryClient } from '@tanstack/vue-query'
import { isApiError } from './http'

export function createAppQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        refetchOnWindowFocus: false,
        retry: (failureCount, error) =>
          isApiError(error) && error.kind === 'network' && failureCount < 2,
      },
      mutations: { retry: false },
    },
  })
}
