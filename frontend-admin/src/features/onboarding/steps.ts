// Los ocho pasos del asistente (RF-PD-03), en el orden del contrato
// (`SetupStep`), y la clave i18n de cada uno.
//
// `compliance_profile` es el unico paso cuyo nombre de contrato no coincide
// con la clave i18n: `snake_case` no es una clave JSON valida sin comillas, y
// el resto del proyecto usa `camelCase` para las claves de traduccion (doc 02
// §3.5), asi que aqui se traduce uno a otro en un unico sitio.
import type { SetupStep } from '@/shared/api/types'

export const STEP_ORDER: readonly SetupStep[] = [
  'administrator',
  'organisation',
  'site',
  'departments',
  'compliance_profile',
  'employees',
  'license',
  'kiosk',
]

function i18nSegment(step: SetupStep): string {
  return step === 'compliance_profile' ? 'complianceProfile' : step
}

export function stepHeadingKey(step: SetupStep): string {
  return `onboarding.steps.${i18nSegment(step)}.heading`
}
