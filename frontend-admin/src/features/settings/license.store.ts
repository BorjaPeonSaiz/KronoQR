// Estado de la licencia, compartido entre la pantalla y el banner persistente
// (RF-PD-04, RF-PD-05, ADR-019, ADR-028).
//
// ## Por que un store y no una llamada en cada componente
//
// Porque hay dos consumidores y tienen que decir lo mismo: el banner del marco
// —visible en TODAS las pantallas— y la pantalla de licencia. Con dos llamadas
// independientes, activar una clave dejaria el banner anunciando una caducidad
// que acaba de dejar de ser cierta hasta la siguiente recarga, que es
// exactamente el tipo de aviso que la gente aprende a ignorar.
//
// ## Solo lo carga quien puede verlo
//
// `GET /api/v1/license` es de `admin` (regla dura 18). El banner comprueba el
// ambito antes de pedirlo: para el resto de los roles este store se queda vacio
// y no se pinta nada. Es cortesia, no seguridad — la de verdad esta en la
// policy del servidor.
//
// ## Un fallo al cargarlo no rompe el panel
//
// Si la llamada falla, el banner no se pinta y ya esta. La licencia no puede
// impedir usar el producto (regla dura 15), y eso incluye no poder impedir que
// se pinte la pantalla de plantilla porque su consulta devolvio un `500`.
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { License, LicenseState } from '@/shared/api/types'
import { activateLicense, fetchLicense } from './license.api'

export const useLicenseStore = defineStore('license', () => {
  const license = ref<License | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)
  const loaded = ref(false)

  /** Si hay que enseñar el banner persistente. Lo decide el servidor. */
  const needsNotice = computed<boolean>(() => license.value?.meta.needs_notice ?? false)

  /**
   * Los dos tipados con las uniones del contrato y no con `string`: son lo que
   * gobierna el color del banner y la clave de traduccion del texto, asi que un
   * valor nuevo en el servidor tiene que ser un error de compilacion aqui y no
   * un banner sin color con la clave impresa en crudo.
   */
  const severity = computed<License['data']['severity']>(
    () => license.value?.data.severity ?? 'none',
  )

  const state = computed<LicenseState>(() => license.value?.data.state ?? 'absent')

  /** Las magnitudes del plan que hoy estan por encima de lo contratado. */
  const exceededLimits = computed(() =>
    (license.value?.data.limits ?? []).filter((limit) => limit.exceeded),
  )

  async function load(force = false): Promise<void> {
    if (loaded.value && !force) {
      return
    }

    loading.value = true
    error.value = null

    try {
      license.value = await fetchLicense()
      loaded.value = true
    } catch (failure) {
      error.value = failure
      license.value = null
    } finally {
      loading.value = false
    }
  }

  /**
   * Activa una clave y **deja el estado nuevo en el store**, para que el banner
   * desaparezca en el acto y no en la siguiente recarga.
   *
   * Los fallos suben: quien acaba de pegar una clave tiene que ver el `422` con
   * su motivo, y el store no sabe en que idioma pintarlo.
   */
  async function activate(signedKey: string): Promise<void> {
    license.value = await activateLicense(signedKey)
    loaded.value = true
    error.value = null
  }

  return {
    license,
    loading,
    error,
    loaded,
    needsNotice,
    severity,
    state,
    exceededLimits,
    load,
    activate,
  }
})
