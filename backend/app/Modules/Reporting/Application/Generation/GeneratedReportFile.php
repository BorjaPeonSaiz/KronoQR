<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Generation;

/**
 * Lo que una generacion deja escrito y **lo que hay que contar de ello** al
 * cerrar la fila (RF-IN-06, RS-05, V5-PA-4).
 *
 * La huella y el tamaño no estan aqui: los mide el cierre sobre el fichero ya
 * escrito, que es la unica huella que sirve para comprobar que la descarga
 * llego entera.
 */
final readonly class GeneratedReportFile
{
    /**
     * @param  list<string>  $criteria  Ya traducidos al idioma de la instalacion.
     * @param  list<string>  $employeeUuids  Afectados enumerables (tope de 50), nunca nombres.
     * @param  string|null  $disclosureDataset  El `personal_data.accessed` que acompaña al cierre, o
     *                                          nulo si el fichero lleva su propio asiento (`legal`) o
     *                                          no lleva datos personales (`adoption`).
     * @param  array<string, scalar>  $disclosureContext
     * @param  array<string, scalar|null>|null  $legalExport  El `legal_export.generated` de la exportacion
     *                                                        para la Inspeccion.
     * @param  array{from: string, to: string}|null  $adoptionPeriod  El periodo del cuadro de impacto, para su
     *                                                                `adoption_report.exported`.
     */
    public function __construct(
        public string $fileName,
        public string $path,
        public int $rowCount,
        public array $criteria,
        public array $employeeUuids = [],
        public ?string $disclosureDataset = null,
        public int $disclosureRecordCount = 0,
        public array $disclosureContext = [],
        public ?array $legalExport = null,
        public ?array $adoptionPeriod = null,
    ) {}
}
