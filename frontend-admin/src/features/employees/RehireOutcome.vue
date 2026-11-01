<script setup lang="ts">
// Lo que sigue a una readmision, en el orden en que importa (RN-27, ADR-062 §6):
//
//  1. El PIN nuevo, si se pidio (`reset_pin`): se ve UNA vez y es lo mas fragil,
//     asi que va primero. Una persona usa el dialogo de siempre (`PinRevealDialog`,
//     con su registro de entrega); varias, una tabla (`RehirePinsDialog`).
//  2. La tarjeta nueva, pendiente de imprimir: se ofrece imprimirla en el acto
//     (`RehireCardDialog`), con el aviso de que imprimir acuña el QR.
//
// El padre le pasa el resultado y lo suelta en `done`: al soltarlo, los PIN en claro
// dejan de existir en el navegador. Este componente no guarda nada.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePersonName } from '@/shared/people/personName'
import type { RehiredEmployee } from '@/shared/api/types'
import PinRevealDialog from './PinRevealDialog.vue'
import RehireCardDialog from './RehireCardDialog.vue'
import RehirePinsDialog from './RehirePinsDialog.vue'
import type { RehireCardItem, RehirePinRow } from './rehirePlan'

const props = defineProps<{ results: readonly RehiredEmployee[] }>()

const emit = defineEmits<{
  /** Se termino de enseñar PIN y tarjetas: quien contiene esto suelta el resultado. */
  done: []
  /** Se registro la entrega de un PIN desde el dialogo: hay que releer la ficha. */
  pinDelivered: []
}>()

const { t } = useI18n()
const { personName } = usePersonName()

function nameOf(result: RehiredEmployee): string {
  return personName(
    result.employee.first_name,
    result.employee.last_name,
    result.employee.anonymized_at,
  )
}

const pinRows = computed<RehirePinRow[]>(() =>
  props.results.flatMap((result) =>
    result.pin === undefined
      ? []
      : [
          {
            employeeUuid: result.employee.uuid,
            name: nameOf(result),
            code: result.employee.employee_code,
            pin: result.pin.pin,
          },
        ],
  ),
)

const cards = computed<RehireCardItem[]>(() =>
  props.results.map((result) => ({
    employeeUuid: result.employee.uuid,
    credentialUuid: result.credential.uuid,
    name: nameOf(result),
  })),
)

const pinsAcknowledged = ref(false)

const singleResult = computed(() => (props.results.length === 1 ? props.results[0] : undefined))
const showPins = computed(() => pinRows.value.length > 0 && !pinsAcknowledged.value)

function onPinDelivered(): void {
  pinsAcknowledged.value = true
  emit('pinDelivered')
}
</script>

<template>
  <template v-if="showPins">
    <PinRevealDialog
      v-if="singleResult !== undefined && singleResult.pin !== undefined"
      :pin="singleResult.pin"
      :employee-name="nameOf(singleResult)"
      :employee-code="singleResult.employee.employee_code"
      @acknowledged="pinsAcknowledged = true"
      @delivered="onPinDelivered"
    >
      <p class="mt-1 text-sm text-kq-text-muted" data-test="pin-next">
        {{ t('employees.rehire.pins.next') }}
      </p>
    </PinRevealDialog>
    <RehirePinsDialog v-else :rows="pinRows" @acknowledged="pinsAcknowledged = true" />
  </template>
  <RehireCardDialog v-else :items="cards" @done="emit('done')" />
</template>
