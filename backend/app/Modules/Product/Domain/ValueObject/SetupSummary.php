<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Lo que hay al cerrar el asistente, **en cifras** (RF-PD-03, paso final).
 *
 * ## Ninguna persona
 *
 * Ni un nombre, ni un correo, ni un UUID. Es la pantalla que dice que queda por
 * hacer antes del primer dia, y para eso bastan los numeros; el detalle persona
 * a persona esta en `GET /api/v1/credentials/status`, que exige otro ambito y
 * deja constancia de quien lo consulto (RF-QR-08, ADR-037).
 *
 * ## `credentialsPending` es la cifra que decide el primer dia
 *
 * Sin tarjeta impresa y entregada no se ficha (ADR-014), y emitir e imprimir
 * tiene logistica detras: por eso el doc 05 §10.2 recomienda hacerlo **con dias
 * de antelacion**. Importar cuarenta personas y cerrar el asistente con
 * `credentials_pending: 40` es exactamente el escenario que el panel de estado
 * de credenciales existe para que nadie descubra delante de la tablet a las
 * 06:00.
 *
 * ## La licencia sale con SU enum, y `absent` es normal
 *
 * {@see LicenseState} y no un resumen de cuatro palabras propio de esta
 * pantalla: dos vocabularios para el mismo hecho acaban divergiendo el dia que
 * uno de los dos gana un valor. Una puesta en marcha sin clave es lo habitual y
 * **no impide nada del registro legal** (ADR-019, regla dura 15); se informa
 * para que el resumen pueda decirlo, no para condicionar nada.
 */
final readonly class SetupSummary
{
    public function __construct(
        public int $employees,
        public int $departments,
        public int $credentialsPending,
        public LicenseState $license,
        public int $kiosks,
    ) {}
}
