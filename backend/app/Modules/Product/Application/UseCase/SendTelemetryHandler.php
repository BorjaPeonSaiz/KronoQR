<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\TelemetrySender;
use App\Modules\Product\Application\Port\TelemetryStateStore;
use App\Modules\Product\Domain\ValueObject\TelemetryBlockReason;
use App\Modules\Product\Domain\ValueObject\TelemetryState;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\Feature;
use Psr\Log\LoggerInterface;

/**
 * Envia la telemetria si —y solo si— se cumplen las tres condiciones
 * (**RF-PD-12**, ADR-020, ADR-023, ficha 5.10 puntos 8 y 9).
 *
 * ## Las tres condiciones se comprueban ANTES de construir nada
 *
 * `TELEMETRY_ENABLED`, un destino configurado **y en `https`** y `telemetry`
 * concedida por la licencia. Con cualquiera sin cumplir se devuelve el motivo y
 * **no se lee una sola serie, ni una sola tabla, ni se toca la red**. Es la
 * diferencia entre un
 * producto que viene apagado y uno que viene apagado *pero mira*: la ficha exige
 * que el sistema funcione «identicamente» sin telemetria, y eso incluye no
 * gastar una consulta en ella.
 *
 * Una prueba unitaria lo comprueba por donde de verdad se nota: con la
 * telemetria apagada, los dobles de {@see TelemetrySender} y de los contadores
 * no reciben ni una llamada.
 *
 * ## Nunca en el camino de una peticion
 *
 * A este caso de uso solo llega `product:telemetry --send`, que ejecuta el
 * planificador una vez por semana (`routes/console.php`). No hay controlador que
 * lo invoque ni middleware que lo dispare: un fichaje no puede esperar a un
 * `POST` a internet (paso 7 de la ficha, regla dura 19).
 *
 * ## Un fallo deja `notice`, jamas `error`
 *
 * El escenario normal de este producto es un hotel sin salida a internet (doc 02
 * §11.6.2). Un `error` semanal en el log de una instalacion perfectamente sana
 * seria ruido que acabaria enseñando a ignorar el log, y ademas es justo el
 * «recordatorio insistente» que RF-PD-12 prohibe. `doctor` tampoco comprueba la
 * telemetria, por lo mismo.
 *
 * ## El acumulado solo avanza si la entrega sale bien
 *
 * Asi una semana sin red no pierde su uso: el envio siguiente cubre las dos.
 *
 * ## Un destino que no sea `https` se trata como si no hubiera destino
 *
 * `configuracion.md` §3 quinquies promete al cliente que el envio va «sobre
 * HTTPS con el certificado verificado». Sin esta comprobacion, esa promesa se
 * rompe escribiendo cuatro caracteres en el `.env` y el producto no diria nada:
 * el documento saldria en claro por la red del hotel, donde cualquiera podria
 * leerlo y alterarlo. No lleva datos personales, pero si el tramo de plantilla,
 * el estado de la licencia y el veredicto de cada comprobacion de `doctor`.
 *
 * **De quien esta al otro lado responde el cliente.** El producto garantiza el
 * canal -TLS verificado, sin redirecciones-, no el destino: el destino lo elige
 * quien escribe la variable. Es un riesgo aceptado y esta anotado como tal en el
 * doc 07 §6.
 */
final readonly class SendTelemetryHandler
{
    public function __construct(
        private BuildTelemetryReportHandler $reports,
        private TelemetrySender $sender,
        private TelemetryStateStore $state,
        private FeatureGate $features,
        private Clock $clock,
        private LoggerInterface $logger,
        private bool $enabled,
        private string $endpoint,
    ) {}

    public function handle(): TelemetryOutcome
    {
        $blocked = $this->blockedBy();

        if ($blocked instanceof TelemetryBlockReason) {
            return TelemetryOutcome::blocked($blocked);
        }

        // `establish()` y no `stored()`: es el envio, y el envio SI fija la
        // identidad de la instalacion. La vista previa no (ver `preview()`).
        $state = $this->state->establish();
        $draft = $this->reports->handle($state);
        $delivery = $this->sender->send($draft->report, $this->endpoint);
        $now = $this->clock->now();

        $this->state->save($delivery->delivered
            ? $state->succeeded($now, $draft->counters)
            : $state->failed($now, $delivery->failure ?? 'unknown'));

        if (! $delivery->delivered) {
            // Sin URL y sin mensaje de excepcion: la URL puede llevar un token en
            // la ruta y el mensaje lleva el host. La clase o el codigo bastan
            // para saber si es «no hay red» o «el destino contesta 500».
            $this->logger->notice('product.telemetry_not_delivered', [
                'failure' => $delivery->failure,
                'status_code' => $delivery->statusCode,
                'attempts' => $delivery->attempts,
            ]);
        }

        return TelemetryOutcome::attempted($draft, $delivery);
    }

    /**
     * El documento que se enviaria, **se envie o no**.
     *
     * Lo imprime `product:telemetry` sin `--send`. Que se pueda construir con la
     * telemetria apagada no contradice nada: aqui nadie sale a la red y el
     * documento se queda en la terminal de quien lo pidio. Es la unica forma de
     * cumplir lo que la ficha exige —*que el cliente decida con la lista
     * delante*— sin obligarle a activarla para ver lo que activaria.
     */
    public function preview(): TelemetryOutcome
    {
        // NO PERSISTE NADA. Mirar no puede tener efectos: quien ejecuta el
        // comando sin `--send` esta decidiendo si activa la telemetria, y una
        // consulta que dejara un fichero con la identidad de la instalacion en
        // el disco de quien decidio que no seria una respuesta distinta de la
        // pregunta. Si todavia no hay identidad, se enseña una provisional y la
        // salida del comando dice que lo es.
        $stored = $this->state->stored();

        return TelemetryOutcome::preview(
            $this->reports->handle($stored ?? $this->state->provisional()),
            $this->blockedBy(),
            $stored instanceof TelemetryState,
        );
    }

    /**
     * El orden importa: primero la voluntad del cliente, despues su
     * configuracion y solo al final el plan. Ver {@see TelemetryBlockReason}.
     */
    private function blockedBy(): ?TelemetryBlockReason
    {
        if (! $this->enabled) {
            return TelemetryBlockReason::DisabledByConfiguration;
        }

        $endpoint = trim($this->endpoint);

        if ($endpoint === '') {
            return TelemetryBlockReason::EndpointNotConfigured;
        }

        if (strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') {
            return TelemetryBlockReason::EndpointNotHttps;
        }

        if (! $this->features->isEnabled(Feature::Telemetry)) {
            return TelemetryBlockReason::NotInLicense;
        }

        return null;
    }
}
