// La consulta del detalle de jornada, con su cache.
//
// Vive en un composable y no dentro de la vista por dos motivos: la bandeja de
// incidencias (2.5) consume la misma consulta, y una clave de cache repetida a
// mano en dos pantallas es una cache que se invalida solo en una.
//
// `keepPreviousData` es deliberado: al cambiar el rango, la pantalla mantiene lo
// que ya se veia mientras llega lo nuevo en vez de parpadear a vacio. Con meses
// de historico ese parpadeo cuesta cada vez que alguien mueve una fecha.
import { computed, toValue } from 'vue'
import type { MaybeRefOrGetter } from 'vue'
import { keepPreviousData, useQuery } from '@tanstack/vue-query'
import type { WorkDateRange } from './workdays.api'
import { listEmployeeWorkDays } from './workdays.api'

/** Prefijo de la clave de cache. Compartido para poder invalidar tras una correccion. */
export const WORKDAYS_QUERY_KEY = 'employee-workdays'

export function useEmployeeWorkDays(
  uuid: MaybeRefOrGetter<string>,
  range: MaybeRefOrGetter<WorkDateRange>,
) {
  const employeeUuid = computed(() => toValue(uuid))
  const requested = computed(() => toValue(range))

  return useQuery({
    queryKey: computed(() => [WORKDAYS_QUERY_KEY, employeeUuid.value, requested.value] as const),
    queryFn: () => listEmployeeWorkDays(employeeUuid.value, requested.value),
    placeholderData: keepPreviousData,
  })
}
