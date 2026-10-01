// Contratos de un empleado (RF-GP-02): la serie historica y el alta.
//
// Solo hay lectura y alta, porque el contrato de la API no ofrece mas: un
// contrato no se edita ni se borra, se registra otro y el anterior queda cerrado
// con su fecha (regla dura 5). Aqui no se inventa ni un `PATCH` ni un `DELETE`.
import { requestJson } from '@kronoqr/web-kit/http'
import type {
  CreateEmploymentContractRequest,
  EmploymentContract,
  EmploymentContractCollection,
} from '@/shared/api/types'

/** La serie completa, del mas antiguo al mas reciente. Sin paginacion (contrato). */
export function listEmploymentContracts(uuid: string): Promise<EmploymentContractCollection> {
  return requestJson<EmploymentContractCollection>(`/api/v1/employees/${uuid}/contracts`)
}

/**
 * Registra un contrato abierto y el servidor cierra el vigente el dia anterior.
 * Un `409` significa que el estado ya no es el que la pantalla mostraba.
 */
export function registerEmploymentContract(
  uuid: string,
  body: CreateEmploymentContractRequest,
): Promise<EmploymentContract> {
  return requestJson<EmploymentContract>(`/api/v1/employees/${uuid}/contracts`, {
    method: 'POST',
    body,
  })
}
