<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;

/**
 * Sonda `mail.alert_recipients` de `product:doctor` (RF-PD-13, tarea 3.2,
 * decisiones 5 y 17i).
 *
 * ## La pregunta que responde
 *
 * «Si esta noche se rompe la cadena de auditoria, ¿lo lee alguien?»
 *
 * El perfil `observability` levanta Prometheus, Alertmanager y las reglas del
 * doc 01 §9.3, y cada regla lleva la etiqueta `destinatario` con uno de tres
 * valores: `it-cliente`, `rrhh` o `seguridad`. Cada uno se entrega a un buzon de
 * correo del cliente y, opcionalmente, a un webhook suyo —`ALERT_EMAIL_*`,
 * `ALERT_WEBHOOK_*`—, porque nada del cliente vive en el repositorio (regla dura
 * 13, ADR-017). Los seis nacen **vacios**.
 *
 * Con un destino vacio, Alertmanager no falla: sencillamente no genera esa
 * entrega, y las alertas de ese destinatario se encienden y se apagan sin que
 * nadie las vea. **Una instalacion que se cree vigilada es peor que una que sabe
 * que no lo esta**, y este es el unico sitio del producto donde eso se puede
 * decir: Alertmanager no tiene a quien avisar de que no tiene a quien avisar.
 *
 * ## Papel a papel, y no «alguno de los tres»
 *
 * Es lo que corrigio la revision de seguridad (decision 17i). La primera version
 * salia en verde en cuanto **un** destino estaba puesto, y el reparto de
 * destinatarios del §9.3 no es decorativo: `RoturaDeCadenaDeAuditoria` va a
 * `seguridad` y `TurnoAbiertoProlongado` a `rrhh`. Con solo `ALERT_EMAIL_IT`
 * relleno —el caso normal de una instalacion recien puesta en marcha— la rotura
 * de la cadena no llegaba a nadie y `doctor` decia que todo estaba bien, que es
 * exactamente el fallo que esta sonda existe para no cometer.
 *
 * ## Correo **o** webhook, indistintamente
 *
 * Un hotel que reciba las alertas en su chat corporativo esta vigilado. Lo que
 * se comprueba es que el destinatario tenga **al menos un camino**, no que tenga
 * los dos: exigir el correo obligaria a inventarse un buzon a quien ya tiene una
 * via mejor, y una comprobacion que se puede satisfacer con un valor falso deja
 * de comprobar nada.
 *
 * ## Aviso y nunca fallo
 *
 * `warning` y no `failure`, y es deliberado por dos motivos. El primero: no
 * impide fichar ni consultar el registro, que es el criterio con el que
 * `product:doctor` reparte los estados. El segundo: `failure` devuelve `2`, que
 * `install.sh` y `update.sh` traducen a un codigo de aborto, y **una instalacion
 * recien puesta en marcha todavia no conoce el buzon de informatica del hotel**.
 * Parar la instalacion por eso obligaria a inventarse una direccion, que es el
 * desenlace que esta comprobacion existe para evitar.
 *
 * ## Familia `mail` y no una propia
 *
 * El informe se lee de arriba abajo y la entrega principal es correo: quien
 * acaba de leer «el SMTP responde» es quien tiene que leer a continuacion «y las
 * alertas de seguridad no tienen a donde ir». Una familia nueva para una linea
 * alargaria el informe sin anadir una categoria que alguien reconozca.
 *
 * ## Ni una direccion ni una URL en `details`
 *
 * El informe viaja en el paquete de diagnostico (ADR-020). Una direccion de
 * correo identifica a una persona de la organizacion del cliente y **una URL de
 * webhook es un secreto portador**: quien la tiene puede escribir en ese canal.
 * Lo que se publica son los **nombres de papel** —`it-cliente`, `rrhh`,
 * `seguridad`—, que son ademas los que aparecen en las reglas y en la guia, y
 * por tanto los unicos que sirven para arreglarlo.
 */
final readonly class AlertRecipientsProbe implements DoctorProbe
{
    /** El perfil de Compose que enciende Prometheus y Alertmanager. */
    private const string OBSERVABILITY_PROFILE = 'observability';

    /**
     * @param  string  $composeProfiles  Valor de `COMPOSE_PROFILES`, tal cual.
     * @param  array<string, array{email: string, webhook: string}>  $destinations
     *                                                                              Papel de la etiqueta `destinatario` => sus dos caminos de entrega,
     *                                                                              con los vacios dentro.
     */
    public function __construct(
        private string $composeProfiles,
        private array $destinations,
    ) {}

    public function family(): string
    {
        return 'mail';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        $unreachable = $this->recipientsWithoutDestination();

        $details = [
            'observability_profile' => $this->observabilityIsOn(),
            // Los NOMBRES DE PAPEL, jamas las direcciones ni las URL.
            'recipients_without_destination' => $unreachable,
            'expected_recipients' => array_keys($this->destinations),
        ];

        $params = [
            'roles' => implode(', ', $unreachable),
            'missing' => \count($unreachable),
            'expected' => \count($this->destinations),
        ];

        if (! $this->observabilityIsOn()) {
            // Sin perfil no hay reglas que evaluar ni Alertmanager que enrute:
            // que los destinos esten vacios es coherente, no un descuido. Lo que
            // se pierde con el perfil apagado esta escrito en `operacion.md`, y
            // no es trabajo de esta sonda repetirlo.
            return [new DoctorFinding('mail.alert_recipients', DoctorStatus::Ok, $params, $details, 'disabled')];
        }

        if ($unreachable !== []) {
            return [DoctorFinding::warning('mail.alert_recipients', params: $params, details: $details)];
        }

        return [DoctorFinding::ok('mail.alert_recipients', $details, $params)];
    }

    /**
     * Los papeles que no tienen NI correo NI webhook, en el orden en que se
     * declaran: el mismo del `.env` y el de la guia, para que la lista del
     * informe se pueda seguir de arriba abajo.
     *
     * @return list<string>
     */
    private function recipientsWithoutDestination(): array
    {
        $unreachable = [];

        foreach ($this->destinations as $role => $destination) {
            if (trim($destination['email']) === '' && trim($destination['webhook']) === '') {
                $unreachable[] = $role;
            }
        }

        return $unreachable;
    }

    /**
     * `COMPOSE_PROFILES` es una lista separada por comas. Se compara entrada a
     * entrada y no con `str_contains()`: un perfil llamado `observability-lite`
     * no es este, y darlo por bueno haria que la comprobacion callara justo en
     * la instalacion mas rara.
     */
    private function observabilityIsOn(): bool
    {
        return array_any(
            explode(',', $this->composeProfiles),
            static fn (string $profile): bool => trim($profile) === self::OBSERVABILITY_PROFILE,
        );
    }
}
