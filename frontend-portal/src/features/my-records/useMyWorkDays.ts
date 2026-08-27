// La consulta de mi registro de jornada.
//
// Sin libreria de cache: el portal es una unica pantalla que consulta un unico
// recurso a la vez, y una libreria de consultas completa no aporta nada que un
// `watch` no de ya, a cambio de bytes que un movil con datos propios tiene que
// descargar (doc 02 §11, bundle pequeño).
//
// `isFetching` distingue «cargando por primera vez» de «actualizando lo que ya
// se ve»: al cambiar el rango, la pantalla no tiene por que parpadear a vacio.
import { ref, watch } from 'vue'
import type { Ref } from 'vue'
import type { EmployeeWorkDays } from '@/shared/api/types'
import { listMyWorkDays } from './workdays.api'
import type { WorkDateRange } from './workdays.api'

export interface UseMyWorkDaysResult {
  data: Ref<EmployeeWorkDays | undefined>
  error: Ref<unknown>
  /** Sin ningun resultado todavia, ni el anterior: es la primera carga. */
  isPending: Ref<boolean>
  /** Hay una peticion en curso, con o sin datos previos que enseñar mientras tanto. */
  isFetching: Ref<boolean>
  reload: () => Promise<void>
}

export function useMyWorkDays(range: Ref<WorkDateRange>): UseMyWorkDaysResult {
  const data = ref<EmployeeWorkDays | undefined>(undefined)
  const error = ref<unknown>(null)
  const isPending = ref(true)
  const isFetching = ref(false)

  async function load(): Promise<void> {
    isFetching.value = true
    error.value = null

    try {
      data.value = await listMyWorkDays(range.value)
    } catch (caught) {
      error.value = caught
    } finally {
      isFetching.value = false
      isPending.value = false
    }
  }

  watch(range, () => void load(), { immediate: true, deep: true })

  return { data, error, isPending, isFetching, reload: load }
}
