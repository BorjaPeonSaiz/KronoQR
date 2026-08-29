<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthFailureReason;
use App\Modules\Shared\Domain\ValueObject\AuthOutcome;

/**
 * `kronoqr_auth_attempts_total{channel,outcome}` (doc 02 §8.2, OWASP A09).
 *
 * **Que dice esta metrica.** Es la unica señal barata que distingue «hoy la
 * gente se equivoca mas» de «alguien esta probando credenciales»: la proporcion
 * de `failure` sobre `success` por canal, y la aparicion de `lockout`, que en
 * operacion normal es casi siempre cero. Las alertas de A09 se escriben sobre
 * este nombre exacto, asi que no se renombra sin cambiarlas.
 *
 * **Solo dos etiquetas, y ninguna identifica a nadie** (regla dura 21, RGPD).
 * Ni `employee_uuid`, ni cuenta, ni IP: una serie temporal por persona seria un
 * registro paralelo de quien se equivoca al entrar, sin retencion ni control de
 * acceso. La cardinalidad es tres canales por tres desenlaces: nueve series en
 * toda la instalacion.
 *
 * **Tampoco el motivo del fallo.** {@see AuthFailureReason}
 * se queda en el log: en una etiqueta acabaria en un panel, y un panel que
 * separa «no existe» de «no coincide» reconstruye desde fuera lo que RS-03
 * existe para ocultar.
 *
 * Es un puerto y no una llamada directa por lo mismo que
 * `Attendance\Application\Port\ScanMetrics` —nombrado en prosa y no con `@see`,
 * porque una referencia resoluble seria una dependencia entre modulos que la
 * frontera del §1.6 no concede—: quien mide no sabe si detras hay Redis, un
 * fichero para el colector *textfile* o nada.
 */
interface AuthenticationMetrics
{
    /**
     * Un intento de autenticacion mas, con su canal y su desenlace.
     *
     * **Medir no puede impedir entrar.** La implementacion traga cualquier fallo
     * del almacen: perder un contador es infinitamente mas barato que devolver un
     * `500` a quien acaba de teclear bien su contrasena — o que dejar a alguien
     * sin fichar, que es la regla dura 19.
     */
    public function attempt(AuthChannel $channel, AuthOutcome $outcome): void;
}
