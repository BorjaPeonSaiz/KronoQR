// Estado del asistente de puesta en marcha (RF-PD-03), compartido entre la
// guarda de rutas (`router/guards.ts`) y `OnboardingView`.
//
// ## Por que un store y no una llamada en cada componente
//
// La guarda necesita saber, en CADA navegacion, si la instalacion sigue sin
// terminar de configurarse (`available`) para poder llevar a `/setup` — y
// hacerlo sin repetir la llamada en cada clic seria pedirle a
// `PRODUCT_SETUP_RATE_LIMIT` (10/min de serie) que aguante una peticion por
// pantalla. Se pide UNA vez por carga de la aplicacion (como `session.restore`)
// y se guarda aqui; el propio asistente actualiza este mismo estado segun
// resuelve cada paso, para que la guarda vea el progreso al vuelo sin una
// segunda fuente de verdad.
//
// ## Dos endpoints, una sola carga
//
// `GET /setup/status` es publico y nunca trae `steps` (revision de la 5.5: lo
// que revela sin autenticar es solo si el asistente sigue abierto). El detalle
// —que paso toca, cual se omitio— vive en `GET /setup/steps`, que exige la
// sesion del administrador. `load()` elige uno u otro segun haya o no un token
// en `session.store`: **sin sesion, la unica pantalla que se puede resolver es
// la del primer administrador** (paso derivado, no depende de `steps`), y
// `OnboardingView` la pinta como caso especial mientras `stepsKnown` es
// `false`. En cuanto ese paso confirma el segundo factor, `AdministratorStep`
// llama a `refresh()` con la sesion ya en el store, y esta funcion pasa sola a
// pedir `GET /setup/steps`.
//
// Si el token guardado ya no vale, `fetchSetupSteps` responde `401` y el
// manejador global de sesion caducada (`main.ts`, `setUnauthenticatedHandler`)
// limpia la sesion y manda a `/login` sin que este store tenga que duplicar
// esa logica: es la misma via de escape que usa cualquier otra pantalla
// autenticada del panel.
//
// ## Un fallo de red al consultarlo no bloquea el panel
//
// Si la peticion falla, `loaded` se queda en `false` y la guarda deja pasar la
// navegacion normal: una instalacion que ya funciona no puede quedar
// inaccesible porque el endpoint tuviera un corte pasajero. Se reintenta en la
// siguiente navegacion.
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { useSessionStore } from '@/features/auth/session.store'
import type { SetupCompletion, SetupStatus, SetupStep, SetupStepState } from '@/shared/api/types'
import { completeSetup, fetchSetupStatus, fetchSetupSteps, recordSetupStep } from './setup.api'

export const useSetupStore = defineStore('setup', () => {
  const status = ref<SetupStatus | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)
  const loaded = ref(false)
  /**
   * El resumen accionable de `POST /setup/complete`, si ya se cerro el
   * asistente EN ESTA SESION. Vive aqui, junto a `status`, y no en un `ref`
   * local de `OnboardingView`: los dos cambian en la MISMA accion del store
   * (`complete()`), en el mismo tick sincrono, asi que Vue nunca pinta un
   * estado intermedio en el que `available` ya es `false` pero el resumen
   * todavia no existe (que enseñaria un instante la pantalla de «ya
   * configurado» antes del cierre de verdad).
   */
  const completion = ref<SetupCompletion | null>(null)

  /** Si el asistente sigue abierto. `false` mientras no se sabe (por defecto no bloquea). */
  const available = computed(() => status.value?.available ?? false)
  const steps = computed(() => status.value?.steps ?? [])
  /**
   * Si la ULTIMA carga trajo `steps` de verdad (sesion de administrador), y no
   * solo `available`/`completed_at` (respuesta publica). `OnboardingView` lo
   * usa para saber si puede confiar en `steps` para elegir el paso activo, o si
   * lo unico resoluble todavia es el primer administrador (paso especial, sin
   * sesion).
   */
  const stepsKnown = computed(() => status.value?.steps !== undefined)

  function stepState(step: SetupStep): SetupStepState | null {
    return steps.value.find((entry) => entry.step === step)?.state ?? null
  }

  /**
   * `GET /setup/status` (publico, sin `steps`) o `GET /setup/steps`
   * (autenticado, con `steps`), segun si `session.store` ya tiene un token.
   * Sin sesion, el asistente solo puede resolver el primer paso —el alta del
   * administrador, que no necesita `steps` porque es derivado— y eso es
   * exactamente lo que la respuesta publica alcanza a decir.
   */
  async function load(force = false): Promise<void> {
    if (loaded.value && !force) {
      return
    }

    loading.value = true
    error.value = null

    try {
      const session = useSessionStore()

      status.value = session.isAuthenticated ? await fetchSetupSteps() : await fetchSetupStatus()
      loaded.value = true
    } catch (failure) {
      error.value = failure
    } finally {
      loading.value = false
    }
  }

  /**
   * Tras un paso DERIVADO (administrador, centro): no hay `PUT` que lo marque,
   * asi que la unica forma de recoger que ya esta hecho es releer el estado.
   * Llamada tras confirmar el segundo factor del primer administrador, con la
   * sesion ya aplicada en `session.store`: `load(true)` pasa a pedir
   * `GET /setup/steps` sola, sin que quien llama tenga que saberlo.
   */
  async function refresh(): Promise<void> {
    await load(true)
  }

  /** Marca un paso hecho u omitido y adopta el estado que devuelve el servidor. */
  async function recordStep(
    step: Exclude<SetupStep, 'administrator' | 'site'>,
    state: 'completed' | 'skipped',
  ): Promise<void> {
    status.value = await recordSetupStep(step, state)
    loaded.value = true
  }

  /** Cierra el asistente para siempre. Devuelve el resumen accionable del primer dia. */
  async function complete(): Promise<SetupCompletion> {
    const result = await completeSetup()

    // Las dos escrituras, en el mismo tick sincrono: ver el comentario de
    // `completion` mas arriba.
    status.value = result.status
    completion.value = result
    loaded.value = true

    return result
  }

  return {
    status,
    loading,
    error,
    loaded,
    completion,
    available,
    steps,
    stepsKnown,
    stepState,
    load,
    refresh,
    recordStep,
    complete,
  }
})
