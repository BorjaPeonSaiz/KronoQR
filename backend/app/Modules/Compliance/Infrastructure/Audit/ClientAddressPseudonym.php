<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Audit;

use Illuminate\Support\Facades\Config;

/**
 * La direccion de origen convertida en un identificador **estable y no
 * reversible fuera de la instalacion** (regla dura 21, ADR-020, ADR-039, RGPD).
 *
 * ## Donde se usa, y donde ya no
 *
 * **Solo en el log tecnico de autenticacion.** `audit_log` guarda la direccion en
 * claro en su columna `ip`, como los otros cinco escritores de la tabla: ADR-039
 * zanjo que un seudonimo en el payload de tres acciones y la direccion en claro
 * en las otras veinte obligaria a quien investiga a saber de que accion viene
 * cada fila para saber que significa su origen. Esa tabla no sale de la
 * instalacion.
 *
 * El log tecnico si sale: viaja al fabricante dentro del paquete de diagnostico
 * (ADR-020), que va anonimizado por defecto. Una IP de la red interna de un hotel,
 * junto a la hora, dice desde que puesto se trabajo: no es un dato tecnico neutro.
 *
 * Con el hash con clave, la instalacion —que es quien tiene la clave— sigue
 * pudiendo responder «¿cuantos origenes distintos hay detras de estos 400
 * fallos?» y «¿el bloqueo y el acceso correcto de despues vienen del mismo
 * sitio?», que son las dos preguntas que A09 obliga a poder contestar
 * (`docs/runbooks/ataque-a-credenciales.md` §4.3 explica como recalcularlo). El
 * fabricante, que no tiene la clave, no puede reconstruir nada.
 *
 * **De ahi una obligacion de la tarea 5.9**: el paquete de diagnostico no puede
 * incluir jamas `APP_KEY`, ni directamente ni por un volcado de configuracion.
 * Con la clave dentro, esto deja de ser un seudonimo.
 *
 * ## Las tres decisiones
 *
 * 1. **Con clave y no un hash a secas.** El espacio de las direcciones IPv4 son
 *    2^32 valores: un SHA-256 sin clave se invierte con una tabla en minutos, asi
 *    que «hashear la IP» sin mas no anonimiza nada.
 * 2. **La clave es la de la instalacion (`APP_KEY`), derivada con HKDF.** No se
 *    añade un secreto nuevo que custodiar (doc `cliente/instalacion.md`): uno mas
 *    es una fila mas que alguien puede no generar, y este control fallaria en
 *    silencio. La derivacion con `info` propio impide que este uso y cualquier
 *    otro de `APP_KEY` compartan material.
 * 3. **Truncado a 16 hexadecimales.** 64 bits bastan para agrupar los origenes de
 *    una instalacion sin colisiones practicas, y el valor cabe en una linea de
 *    log sin engordar el fichero.
 *
 * **Si la instalacion no tiene `APP_KEY`, no hay seudonimo y no hay campo.**
 * Devolver la IP en claro «porque no se pudo cifrar» seria exactamente el fallo
 * abierto que este objeto existe para evitar.
 */
final readonly class ClientAddressPseudonym
{
    /**
     * Separa este uso de cualquier otro de `APP_KEY`. Cambiarlo reinicia los
     * seudonimos: los del historico dejarian de casar con los nuevos.
     */
    private const string DERIVATION_INFO = 'kronoqr:auth:client-address';

    private const int LENGTH = 16;

    public function of(?string $address): ?string
    {
        if ($address === null || $address === '') {
            return null;
        }

        $key = $this->key();

        if ($key === null) {
            return null;
        }

        return substr(hash_hmac('sha256', $address, $key), 0, self::LENGTH);
    }

    /**
     * Material de clave derivado de `APP_KEY`.
     *
     * Laravel guarda la clave como `base64:...`; se decodifica para derivar
     * sobre los bytes reales y no sobre su representacion, que es lo que hace que
     * el seudonimo no dependa de como este escrita la variable.
     */
    private function key(): ?string
    {
        $appKey = Config::string('app.key', '');

        if ($appKey === '') {
            return null;
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);

            if ($decoded !== false && $decoded !== '') {
                $appKey = $decoded;
            }
        }

        return hash_hkdf('sha256', $appKey, 32, self::DERIVATION_INFO);
    }
}
