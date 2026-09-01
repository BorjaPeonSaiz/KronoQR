<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\LicenseStatus;
use App\Modules\Product\Domain\ValueObject\LicenseVerification;

/**
 * Verifica una clave de licencia **en local y sin red** (RF-PD-04, ADR-018).
 *
 * ## Por que es un puerto
 *
 * Porque la comprobacion criptografica es infraestructura —sodium, base64url,
 * una clave publica que sale de la configuracion— y el caso de uso no tiene por
 * que saber nada de eso. El adaptador es
 * `Product/Infrastructure/Adapter/Ed25519LicenseVerifier`.
 *
 * ## La prohibicion que este puerto expresa
 *
 * **Ninguna implementacion puede hacer una llamada saliente.** No es un consejo:
 * ADR-018 lo decide y `tests/Feature/Product/LicenseVerificationIsLocalTest.php`
 * lo comprueba con un cliente HTTP simulado que hace fallar la prueba si alguien
 * lo invoca. Una activacion en linea convertiria la conectividad del fabricante
 * en punto unico de fallo del registro horario de todos sus clientes, y el §11.6
 * declara la salida a internet **opcional**.
 *
 * ## Y la que NO expresa
 *
 * No dice nada de fechas. Verificar es comprobar quien firmo, no si sigue
 * vigente: la vigencia la decide {@see LicenseStatus}
 * con el instante del puerto `Clock`. Separarlo permite activar una clave
 * caducada —cosa que hay que poder hacer— y probar el dia exacto del vencimiento
 * sin tocar el reloj de la maquina.
 */
interface LicenseVerifier
{
    /**
     * Nunca lanza. Una clave rota es un resultado, no una averia: en el camino
     * de lectura del `FeatureGate` una excepcion no capturada seria un `500` en
     * el panel por culpa de una fila de licencia.
     */
    public function verify(string $signedKey): LicenseVerification;
}
