<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Query\ManagementAccountsQuery;
use App\Modules\Identity\Application\Query\ManagementAccountView;
use App\Modules\Identity\Application\UseCase\CreateManagementAccountHandler;
use App\Modules\Identity\Http\Request\IndexManagementAccountsRequest;
use App\Modules\Identity\Http\Request\StoreManagementAccountRequest;
use App\Modules\Identity\Http\Resource\ManagementAccountProvisionedResource;
use App\Modules\Identity\Http\Resource\ManagementAccountResource;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * `GET` y `POST /api/v1/management-accounts` (**RF-ID-10**).
 *
 * Fino a proposito: la policy esta en la peticion (`admin`, nunca soporte), el
 * ambito `accounts:*` en la ruta, y todo lo demas en el caso de uso.
 */
final class ManagementAccountController extends Controller
{
    public function index(IndexManagementAccountsRequest $request, ManagementAccountsQuery $query): JsonResponse
    {
        $listing = $query->page($request->filter(), $request->page(), $request->perPage());

        return response()->json([
            'data' => array_map(
                static fn (ManagementAccountView $view): array => new ManagementAccountResource($view)->toArray($request),
                $listing->items,
            ),
            'meta' => [
                'page' => $listing->page,
                'per_page' => $listing->perPage,
                'total' => $listing->total,
                'total_pages' => $listing->totalPages,
            ],
        ]);
    }

    public function store(
        StoreManagementAccountRequest $request,
        CreateManagementAccountHandler $handler,
        ManagementAccountsQuery $query,
    ): JsonResponse {
        $provisioned = $handler->handle($request->toCommand());

        $account = $query->find($provisioned->account->uuid)
            // Recien creada en esta misma peticion: no encontrarla es una
            // incoherencia, no un caso de negocio.
            ?? throw new RuntimeException('La cuenta de gestion recien creada no se ha podido releer.');

        return new ManagementAccountProvisionedResource($account, $provisioned)
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED)
            // El cuerpo lleva una contrasena en claro: ni el navegador ni un
            // proxy intermedio pueden guardarlo.
            ->header('Cache-Control', 'no-store, private');
    }
}
