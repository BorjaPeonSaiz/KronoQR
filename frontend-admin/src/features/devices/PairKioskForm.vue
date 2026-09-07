<script setup lang="ts">
// El formulario de vinculacion (RF-PD-06): el codigo de seis digitos que
// muestra la tablet y el nombre del quiosco. Fuente unica, reutilizada por la
// pantalla «Quioscos» (dentro de un dialogo) y por el paso del asistente
// (incrustado en el propio paso).
//
// SIN ENCABEZADO PROPIO A PROPOSITO: quien lo contiene ya tiene uno —el
// titulo del dialogo, o el `<h2>` del paso del asistente— y duplicarlo aqui
// rompería el orden de encabezados de esa pantalla (regla de accesibilidad,
// `heading-order`).
//
// El rechazo del codigo (`pairing-code-rejected`) es GENERICO a proposito
// (regla dura 17): no existe donde el contrato aloje si el codigo no existe,
// ha caducado o ya se uso, y el panel no inventa esa distincion. La unica
// accion siguiente es la misma en los tres casos — pedirle a la tablet que
// muestre un codigo nuevo —, asi que aqui se distingue solo de un error de
// VALIDACION del formulario (`name` en uso), que si lleva su mensaje de campo.
//
// TRAS CONFIRMAR, la pantalla no se cierra ni avanza sola: se enseña un
// resumen de QUE se ha vinculado —version de la app y hora en que la tablet
// pidio el codigo, `PairingConfirmed.request`— para que la persona lo
// contraste con la tablet que tiene delante antes de dar el paso por bueno.
// Es la misma logica que exige toda correccion con consecuencias (CLAUDE.md):
// mostrar el resultado antes de que quien lo mira siga adelante.
import { announce } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { formatInstantWithZone } from '@kronoqr/web-kit/datetime'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Device, PairingConfirmed } from '@/shared/api/types'
import { confirmPairing } from './devices.api'

const props = defineProps<{
  /**
   * La flota ya cargada, SOLO cuando quien contiene el formulario ya la tiene
   * a mano (la pantalla «Quioscos»): permite avisar de la reactivacion ANTES
   * de enviar, si el nombre tecleado coincide con un quiosco revocado. En el
   * paso del asistente no hay lista que pasar, y sin ella no hay aviso previo
   * —el aviso de la reactivacion, `reactivated`, llega igualmente en la
   * respuesta, ya confirmada—.
   */
  existingDevices?: readonly Device[]
}>()

const emit = defineEmits<{
  /**
   * El quiosco quedo vinculado (alta o reactivacion). Se emite EN EL MOMENTO
   * de confirmar, no al cerrar el resumen: quien lo contiene puede refrescar
   * su propia lista de inmediato aunque la persona siga leyendo el resultado
   * en pantalla.
   */
  paired: [result: PairingConfirmed]
}>()

const { t, locale } = useI18n()

const code = ref('')
const name = ref('')
const submitting = ref(false)
const error = ref<unknown>(null)
/** El resultado de la ultima confirmacion, mientras se enseña su resumen. `null` = formulario libre. */
const confirmed = ref<PairingConfirmed | null>(null)

/**
 * Los seis digitos, sin espacios ni separadores. El campo ACEPTA «483 921»
 * —asi es como lo enseña la tablet— y esto es lo unico que lo normaliza: el
 * espacio no se filtra mientras se teclea, para no impedir escribirlo tal
 * cual se lee en la pantalla colgada de la pared.
 */
const normalizedCode = computed(() => code.value.replace(/\D/g, ''))
const codeIsValid = computed(() => /^[0-9]{6}$/.test(normalizedCode.value))
const trimmedName = computed(() => name.value.trim())
const nameIsValid = computed(() => trimmedName.value.length > 0)

/**
 * El quiosco REVOCADO cuyo nombre coincide exactamente con lo tecleado, si lo
 * hay: confirmar con ese nombre lo reactiva (misma fila, mismo `uuid`,
 * ADR-028) en vez de crear uno nuevo. Solo se calcula cuando quien contiene
 * el formulario paso `existingDevices` (la pantalla «Quioscos»).
 */
const matchingRevokedDevice = computed(() => {
  if (props.existingDevices === undefined || trimmedName.value === '') {
    return null
  }

  return (
    props.existingDevices.find(
      (candidate) => candidate.name === trimmedName.value && candidate.status === 'revoked',
    ) ?? null
  )
})

/** Es SOLO el rechazo generico del codigo: la unica respuesta de error de `confirm` sin campo. */
const codeRejected = computed(
  () =>
    isApiError(error.value) &&
    error.value.problem?.type === 'urn:kronoqr:problem:pairing-code-rejected',
)

function fieldErrors(field: string): readonly string[] {
  return isApiError(error.value) ? (error.value.fieldErrors[field] ?? []) : []
}

/**
 * La zona horaria del NAVEGADOR, a proposito y no la del centro: aqui no se
 * enseña un dato del registro legal, se contrasta un instante MUY reciente
 * con el reloj de quien tiene la tablet delante en este momento.
 */
const browserTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone

const appVersionLabel = computed(() => {
  const version = confirmed.value?.request.app_version ?? null

  return version ?? t('devices.pair.confirmed.appVersionUnknown')
})

const requestedAtLabel = computed(() => {
  const requestedAt = confirmed.value?.request.requested_at

  return requestedAt === undefined
    ? ''
    : formatInstantWithZone(requestedAt, browserTimeZone, locale.value)
})

async function submit(): Promise<void> {
  submitting.value = true
  error.value = null

  try {
    const result = await confirmPairing({
      code: normalizedCode.value,
      name: trimmedName.value,
    })

    confirmed.value = result
    announce(
      result.device.reactivated
        ? t('devices.pair.announceReactivated', { name: result.device.name })
        : t('devices.pair.announceCreated', { name: result.device.name }),
    )

    code.value = ''
    name.value = ''
    emit('paired', result)
  } catch (caught) {
    error.value = caught
    // Un codigo mal tecleado no obliga a empezar de cero (el `confirm` fallido
    // no consume la solicitud): se conserva para que solo haya que corregirlo,
    // no volver a copiarlo de la pantalla de la tablet.
  } finally {
    submitting.value = false
  }
}

/** Vuelve al formulario vacio para vincular otro quiosco sin cerrar el dialogo o el paso. */
function pairAnother(): void {
  confirmed.value = null
}
</script>

<template>
  <div v-if="confirmed !== null" class="flex flex-col gap-4">
    <p
      role="status"
      class="rounded-kq border border-kq-success bg-kq-success-soft p-4 text-kq-success"
    >
      {{
        confirmed.device.reactivated
          ? t('devices.pair.confirmed.reactivatedHeading', { name: confirmed.device.name })
          : t('devices.pair.confirmed.createdHeading', { name: confirmed.device.name })
      }}
    </p>

    <dl class="grid grid-cols-[auto,1fr] gap-x-3 gap-y-1 text-sm">
      <dt class="font-medium text-kq-text">{{ t('devices.pair.confirmed.appVersionLabel') }}</dt>
      <dd>{{ appVersionLabel }}</dd>
      <dt class="font-medium text-kq-text">{{ t('devices.pair.confirmed.requestedAtLabel') }}</dt>
      <dd>{{ requestedAtLabel }}</dd>
    </dl>

    <p class="max-w-prose text-kq-text-muted">{{ t('devices.pair.confirmed.advice') }}</p>

    <div>
      <button
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
        @click="pairAnother"
      >
        {{ t('devices.pair.pairAnother') }}
      </button>
    </div>
  </div>

  <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
    <p
      v-if="codeRejected"
      role="alert"
      class="rounded-kq border border-kq-danger bg-kq-danger-soft p-4 text-kq-danger"
    >
      {{ t('devices.pair.codeRejected') }}
    </p>
    <ErrorNotice
      v-else-if="error !== null"
      :error="error"
      :field-labels="{ name: t('devices.pair.fields.name') }"
    />

    <FormField
      v-slot="field"
      :label="t('devices.pair.fields.code')"
      :hint="t('devices.pair.hints.code')"
      :errors="fieldErrors('code')"
      required
    >
      <input
        :id="field.id"
        v-model="code"
        type="text"
        name="code"
        inputmode="numeric"
        autocomplete="one-time-code"
        pattern="[0-9]{3} ?[0-9]{3}"
        maxlength="7"
        required
        :aria-describedby="field.describedBy"
        :aria-invalid="field.invalid"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-center text-lg tracking-[0.3em] text-kq-text"
      />
    </FormField>

    <FormField
      v-slot="field"
      :label="t('devices.pair.fields.name')"
      :hint="t('devices.pair.hints.name')"
      :errors="fieldErrors('name')"
      required
    >
      <input
        :id="field.id"
        v-model="name"
        type="text"
        name="name"
        maxlength="120"
        required
        :aria-describedby="field.describedBy"
        :aria-invalid="field.invalid"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
      />
    </FormField>

    <p
      v-if="matchingRevokedDevice !== null"
      role="status"
      class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-alt p-3 text-sm text-kq-text"
    >
      {{ t('devices.pair.reactivationNotice') }}
    </p>

    <div>
      <button
        type="submit"
        :disabled="submitting || !codeIsValid || !nameIsValid"
        :aria-busy="submitting"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
      >
        {{ submitting ? t('devices.pair.submitting') : t('devices.pair.submit') }}
      </button>
    </div>
  </form>
</template>
