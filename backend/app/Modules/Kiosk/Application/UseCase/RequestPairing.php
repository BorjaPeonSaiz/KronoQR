<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\UseCase;

use App\Modules\Kiosk\Application\Command\RequestPairingCommand;
use App\Modules\Kiosk\Application\Exception\PairingCapacityExhausted;
use App\Modules\Kiosk\Application\Exception\PairingCodeSpaceExhausted;
use App\Modules\Kiosk\Application\Port\KioskMetrics;
use App\Modules\Kiosk\Application\Port\PairingRequests;
use App\Modules\Kiosk\Application\Port\PairingSecrets;
use App\Modules\Kiosk\Application\Query\PairingTicket;
use App\Modules\Shared\Application\Port\Clock;
use Psr\Log\LoggerInterface;

/**
 * Paso 1 de RF-PD-06: la tablet pide emparejarse y recibe el codigo que muestra.
 *
 * ## Es una escritura PUBLICA, y lo que puede hacer es poco a proposito
 *
 * Crea una solicitud pendiente que **no vincula nada, no lee nada y no autoriza
 * nada**. Solo sirve si alguien autenticado la confirma. Es lo que permite que el
 * cliente no tenga que usar SSH para dar de alta un quiosco sin abrir con ello
 * una puerta al producto.
 *
 * ## Dos purgas y una cota, en este orden
 *
 * 1. **Las consumidas y las viejas** (`purgeOlderThan`), a las 24 h. Higiene: la
 *    tabla no crece sin limite.
 * 2. **Las pendientes ya caducadas** (`purgeExpiredPending`), en el acto. Esta no
 *    es higiene sino correccion: el indice unico parcial no sabe de relojes, asi
 *    que una pendiente caducada seguiria reservando su codigo hasta el barrido de
 *    las 24 h, estrechando el espacio de sorteo sin que nadie lo vea.
 * 3. **La cota de solicitudes vivas** (`max_live_pending`), ya con lo caducado
 *    fuera. Es lo que el limitador por IP no puede hacer: aquel frena a UN
 *    origen, y esto mira el conjunto (RS-02).
 *
 * El orden importa: contar antes de purgar daria por vivas las caducadas y
 * cerraria la ruta a quien llega legitimamente.
 *
 * ## Sin transaccion explicita, y es correcto
 *
 * Escrituras independientes sin invariante entre ellas. Envolverlas seria
 * ceremonia: si una purga falla, la solicitud se crea igual y unas filas viejas
 * siguen ahi un rato mas. Lo que si tiene que ser atomico —el sorteo del codigo
 * frente a otro simultaneo— lo resuelve el UNIQUE parcial en el adaptador, no una
 * transaccion.
 *
 * La cota tampoco se toma bajo bloqueo, y tampoco hace falta: es un techo de
 * recursos con un orden de magnitud de margen, no una invariante. Que dos
 * peticiones simultaneas la crucen a la vez y entren veintiuna solicitudes en
 * lugar de veinte no cambia nada; bloquear la tabla en cada peticion publica, si.
 *
 * ## Sin auditoria, y tambien es una decision
 *
 * Pedir un codigo no tiene relevancia legal: no crea dispositivo, no toca el
 * registro horario y no accede a datos de nadie. El acto que se audita es el
 * `confirm`, que es el que tiene actor y el que da de alta el puesto (regla dura
 * 6). Auditar esta llamada —publica y sin autenticar— seria ademas dejar que
 * cualquiera escriba en `audit_log` desde fuera.
 */
final readonly class RequestPairing
{
    /**
     * Cuantos codigos se sortean antes de rendirse.
     *
     * Ocho, y no uno: una colision es normal cuando hay varias solicitudes vivas
     * y no tiene por que llegarle a nadie. Y no infinitos: un bucle sin techo
     * sobre un espacio agotado deja la peticion colgada en lugar de decir que
     * pasa.
     */
    private const int CODE_ATTEMPTS = 8;

    public function __construct(
        private PairingRequests $requests,
        private PairingSecrets $secrets,
        private KioskMetrics $metrics,
        private LoggerInterface $log,
        private Clock $clock,
        /**
         * Vida del codigo, cadencia de sondeo, ventana de purga y cota de
         * solicitudes vivas, **ya resueltas** por `KioskServiceProvider` (regla
         * dura 14 aplicada a la configuracion en general): un caso de uso que
         * consulta la configuracion no se puede probar con dos valores sin tocar
         * la configuracion global.
         */
        private int $codeTtlSeconds,
        private int $pollIntervalSeconds,
        private int $purgeAfterHours,
        private int $maxLivePending,
    ) {}

    /**
     * @throws PairingCapacityExhausted si la instalacion tiene demasiadas solicitudes vivas
     * @throws PairingCodeSpaceExhausted si no queda ningun codigo libre que sortear
     */
    public function handle(RequestPairingCommand $command): PairingTicket
    {
        $now = $this->clock->now();

        $this->requests->purgeOlderThan($now->modify('-'.max(1, $this->purgeAfterHours).' hours'));
        $this->requests->purgeExpiredPending($now);

        $live = $this->requests->countLivePending($now);

        if ($live >= max(1, $this->maxLivePending)) {
            // El motivo real vive aqui y no en la respuesta: al otro lado hay una
            // tablet que casi nunca es la culpable.
            $this->log->warning('pairing_unavailable', [
                'reason' => 'live_pending_cap',
                'live_pending' => $live,
                'cap' => $this->maxLivePending,
            ]);
            $this->metrics->pairingRejected('capacity');

            throw new PairingCapacityExhausted($live, $this->maxLivePending);
        }

        $expiresAt = $now->modify('+'.max(1, $this->codeTtlSeconds).' seconds');

        for ($attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++) {
            $code = $this->secrets->generateCode();
            $secret = $this->secrets->generateSecret();

            $pairingId = $this->requests->create(
                codeHash: $this->secrets->hash($code->value),
                secretHash: $this->secrets->hash($secret),
                appVersion: $command->appVersion,
                expiresAt: $expiresAt,
                now: $now,
            );

            if ($pairingId === null) {
                // El codigo lo tenia otra solicitud pendiente. Se sortea otro: la
                // invariante la defiende el indice unico parcial y no una consulta
                // previa, que tendria condicion de carrera.
                continue;
            }

            $this->metrics->pairingRequested();

            return new PairingTicket(
                pairingId: $pairingId,
                secret: $secret,
                code: $code,
                expiresAt: $expiresAt,
                pollIntervalSeconds: max(1, $this->pollIntervalSeconds),
            );
        }

        $this->log->warning('pairing_unavailable', [
            'reason' => 'code_space_exhausted',
            'attempts' => self::CODE_ATTEMPTS,
            'live_pending' => $live,
        ]);
        $this->metrics->pairingRejected('code_space');

        throw new PairingCodeSpaceExhausted;
    }
}
