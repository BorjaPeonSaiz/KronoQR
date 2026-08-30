<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Workforce\Domain\Exception\OverlappingEmploymentContract;
use App\Modules\Workforce\Domain\Model\EmploymentContract;

/**
 * Los contratos de una persona, con su vigencia (**RF-GP-02**).
 *
 * **Habla en modelos de dominio y nunca en filas** (ADR-025, restriccion 2): el
 * caso de uso recibe {@see EmploymentContract} y no un modelo Eloquent, porque
 * con el modelo tendria tambien `->delete()` y la tentacion de usarlo — y aqui
 * nada se borra (regla dura 5).
 *
 * **No hay `deleteFor()` ni `replace()`.** Un contrato nuevo no sustituye al
 * anterior: lo cierra. Lo unico que se actualiza de una fila existente es su
 * `valid_to`, y eso es lo que hace {@see self::close()}.
 */
interface EmploymentContractRepository
{
    /**
     * Todos los contratos de la persona, del mas antiguo al mas reciente.
     *
     * **Incluye a quien esta de baja** (RN-14, RL-02): dar de baja no borra la
     * ficha y el informe de un periodo pasado sigue necesitando su contrato.
     *
     * Lista vacia si la persona no existe o no tiene ninguno: distinguir los dos
     * casos es de quien pregunta por el empleado, no de este puerto.
     *
     * @return list<EmploymentContract>
     */
    public function forEmployee(string $employeeUuid): array;

    /**
     * El contrato **abierto** (`valid_to IS NULL`) de la persona, o `null`.
     *
     * Como mucho hay uno: lo garantiza `employment_contracts_no_overlap`, no una
     * convencion.
     */
    public function openContractFor(string $employeeUuid): ?EmploymentContract;

    /**
     * Inserta el contrato y devuelve su clave interna.
     *
     * @param  int|null  $registeredByUserId  Cuenta de gestion que lo registra; `null` en una
     *                                        semilla o una importacion, donde no hay nadie detras.
     *
     * @throws OverlappingEmploymentContract si choca con `employment_contracts_no_overlap`
     */
    public function add(EmploymentContract $contract, ?int $registeredByUserId): int;

    /**
     * Fija el `valid_to` de un contrato ya existente.
     *
     * Es la unica escritura sobre una fila anterior que este producto admite, y
     * no contradice la regla dura 5: no sobrescribe lo pactado —ni las horas, ni
     * el tipo de jornada, ni la fecha de inicio—, solo declara hasta cuando
     * estuvo vigente, que es un dato que antes no se sabia.
     */
    public function close(EmploymentContract $contract): void;
}
