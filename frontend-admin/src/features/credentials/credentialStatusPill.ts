// Píldora de estado de una fila de credencial: refleja el ciclo de vida, no
// una decisión estética. Compartida por el tablero (`CredentialBoardView`) y
// por la sección «Tarjeta QR» de la ficha de empleado (`EmployeeDetailView`),
// para que ambas pantallas digan lo mismo del mismo estado.
import type { CredentialLifecycleStatus } from '@/shared/api/types'

export const STATUS_PILL_CLASS: Record<CredentialLifecycleStatus, string> = {
  no_credential: 'bg-kq-surface-alt text-kq-text-muted',
  pending_print: 'bg-kq-warning-soft text-kq-warning',
  pending_delivery: 'bg-kq-warning-soft text-kq-warning',
  delivered: 'bg-kq-success-soft text-kq-success',
  revoked: 'bg-kq-danger-soft text-kq-danger',
}
