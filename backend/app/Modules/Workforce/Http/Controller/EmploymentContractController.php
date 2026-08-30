<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Application\Query\EmploymentContractQueries;
use App\Modules\Workforce\Application\UseCase\RegisterEmploymentContractHandler;
use App\Modules\Workforce\Domain\Model\EmploymentContract;
use App\Modules\Workforce\Http\Request\IndexEmploymentContractRequest;
use App\Modules\Workforce\Http\Request\StoreEmploymentContractRequest;
use App\Modules\Workforce\Http\Resource\EmploymentContractResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Los contratos de una persona: consultarlos y registrar uno nuevo
 * (**RF-GP-02**, tarea 2.8).
 *
 * Delgado como el resto: valida y autoriza el `FormRequest`, invoca el caso de
 * uso y serializa el `Resource`. **Ninguna decision vive aqui**: el cierre del
 * contrato anterior lo hace {@see RegisterEmploymentContractHandler} dentro de
 * su transaccion, el asiento de `audit_log` lo escribe el listener de
 * `Compliance` y las invariantes son del modelo de dominio y del esquema.
 *
 * **No hay `PATCH` ni `DELETE`** (regla dura 5). Un contrato no se edita: se
 * registra otro, y el anterior queda cerrado con su fecha. Corregir una errata
 * —«puse 40 y eran 38»— no tiene endpoint todavia y es deuda consciente: hacerlo
 * bien significa una version nueva con autor y motivo, como en las correcciones
 * del registro horario (RN-13), no un `UPDATE`.
 *
 * **`POST` devuelve `201`** con el contrato ya vigente, para que el panel
 * sustituya la fila sin volver a pedir la lista.
 *
 * **Sin `ScopeGuard`** y no es un olvido: la policy solo admite `{admin, rrhh}`,
 * que tienen alcance completo (RF-ID-03). El dia que un responsable pueda
 * consultar los contratos de su gente, el alcance entra aqui exactamente igual
 * que en `GET /employees/{uuid}/workdays`.
 */
final class EmploymentContractController extends Controller
{
    public function index(
        IndexEmploymentContractRequest $request,
        string $uuid,
        EmploymentContractQueries $contracts,
    ): JsonResponse {
        return response()->json([
            'data' => array_map(
                static fn (EmploymentContract $contract): array => (new EmploymentContractResource($contract))
                    ->toArray($request),
                $contracts->forEmployee($uuid),
            ),
        ]);
    }

    public function store(
        StoreEmploymentContractRequest $request,
        string $uuid,
        RegisterEmploymentContractHandler $handler,
    ): JsonResponse {
        $contract = $handler->handle($request->toCommand($uuid));

        if ($contract === null) {
            // La persona no existe. `404` y no `422`: no hay campo que corregir
            // en el cuerpo, el identificador de la ruta es el que no lleva a
            // ninguna parte. Quien llama ya puede listar la plantilla entera, asi
            // que no hay enumeracion que evitar.
            throw new NotFoundHttpException;
        }

        return (new EmploymentContractResource($contract))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
