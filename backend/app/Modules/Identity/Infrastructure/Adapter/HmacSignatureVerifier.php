<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Application\Port\QrKeyProvider;
use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Identity\Domain\ValueObject\CredentialSecret;
use App\Modules\Identity\Domain\ValueObject\QrPayload;
use App\Modules\Identity\Domain\ValueObject\QrSigningKey;
use App\Modules\Shared\Application\Port\EmployeeRegistry;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * De un payload QR firmado al empleado que hay detras (RF-QR-01, RF-QR-02,
 * RF-QR-03, RS-03).
 *
 * **Es la arista de ADR-025.** El puerto `CredentialResolver` lo declara
 * `Attendance`, que es quien lo necesita, y lo implementa `Identity`, que es
 * donde estan la tabla `credentials` y las claves de firma. De todo `Attendance`
 * este fichero solo alcanza `Application\Port`, y el enlace puerto->adaptador se
 * declara en `IdentityServiceProvider`. `Attendance` no sabe que existe el HMAC.
 *
 * ## Los seis pasos del §5.2, en este orden y sin atajos
 *
 * 1. Comprobar el prefijo `FH1` ({@see QrPayload::parse()}).
 * 2. Resolver la clave por `key_id`. Desconocida o retirada -> rechazo.
 * 3. Recalcular el HMAC y comparar con `hash_equals`.
 * 4. Buscar la credencial por **hash del token**, nunca por el token.
 * 5. Comprobar que no esta revocada y que el empleado sigue activo.
 * 6. Todos los rechazos, identicos desde fuera.
 *
 * **El orden es una decision de seguridad, no de legibilidad.** Consultar la
 * base de datos antes de verificar la firma convertiria este endpoint en un
 * oraculo de existencia: bastaria con enviar tokens al azar y medir para saber
 * cuales corresponden a una tarjeta real, sin conocer la clave. Se verifica
 * primero y se pregunta despues.
 *
 * ## Como se consigue el tiempo constante (RS-03, regla dura 17)
 *
 * Dos mecanismos, y hacen falta los dos:
 *
 * - **Se recorren siempre los mismos pasos.** No hay ningun `return` temprano.
 *   Si el payload esta mal formado se sigue con un payload centinela; si la
 *   clave no existe se firma con una clave centinela; si no hay credencial se
 *   consulta igualmente el padron. Los cuatro rechazos hacen el mismo numero de
 *   operaciones criptograficas y el mismo numero de consultas.
 * - **Un suelo de tiempo para todo rechazo.** Lo anterior iguala el trabajo, no
 *   el reloj: un acierto de indice y un fallo de indice no tardan exactamente lo
 *   mismo, y la varianza de PostgreSQL basta para distinguirlos con suficientes
 *   muestras. El suelo —configurable, 25 ms de serie— absorbe esa diferencia. Se
 *   aplica solo al rechazo: igualar tambien la aceptacion no aportaria nada,
 *   porque la respuesta ya dice si el escaneo se acepto.
 *
 * ## Que se registra y que no
 *
 * El motivo real va al log del servidor y a `scan_events.result` (lo escribe
 * `Attendance`), **nunca al cliente**. En el log no aparece el payload, ni el
 * token, ni su hash, ni el nombre de nadie: solo `employee_uuid` cuando se
 * conoce (regla dura 21). Un log con el token seria una tarjeta valida escrita en
 * texto plano en un fichero que se rota, se copia y viaja en el paquete de
 * diagnostico.
 */
final readonly class HmacSignatureVerifier implements CredentialResolver
{
    /**
     * Payload centinela para cuando el texto leido no tiene la forma del §5.1.
     *
     * Existe para que el camino «malformado» haga exactamente el mismo trabajo
     * que los demas: mismo HMAC, misma consulta. Su token no puede coincidir con
     * ninguno real —tendria que salir del generador de 128 bits— y aunque
     * coincidiera, su firma no verificaria.
     */
    private const string SENTINEL_KEY_ID = '00';

    private const string SENTINEL_TOKEN = 'AAAAAAAAAAAAAAAAAAAAAA';

    private const string SENTINEL_SIGNATURE = 'AAAAAAAAAAAAAAAA';

    /**
     * Clave centinela: 32 bytes fijos, en base64.
     *
     * **No es un secreto y no protege nada.** Su unica funcion es que el paso 3
     * se ejecute tambien cuando el `key_id` es desconocido, para que ese rechazo
     * cueste lo mismo que uno por firma invalida. Ningun payload firmado con ella
     * llega a aceptarse: la comprobacion de «clave conocida» se evalua aparte.
     */
    private const string SENTINEL_SECRET = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    /** UUID de empleado que no existe, para igualar la consulta al padron. */
    private const string SENTINEL_EMPLOYEE_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * Clave interna que ninguna fila puede tener: `employees.id` es un
     * `BIGSERIAL` y empieza en 1. Iguala la traduccion de identificador cuando
     * no hay credencial que traducir.
     */
    private const int SENTINEL_EMPLOYEE_ID = 0;

    public function __construct(
        private QrKeyProvider $keys,
        private CredentialRepository $credentials,
        private EmployeeRegistry $employees,
        private EmployeeDirectory $directory,
        /** Suelo de tiempo de todo rechazo, en milisegundos (RS-03). */
        private int $rejectionFloorMs,
    ) {}

    public function resolve(#[SensitiveParameter] string $qrPayload): CredentialResolution
    {
        $startedAt = hrtime(true);

        // --- Paso 1: forma y prefijo -----------------------------------------
        $parsed = QrPayload::tryParse($qrPayload);
        $payload = $parsed ?? $this->sentinelPayload();

        // --- Paso 2: resolver la clave por key_id ----------------------------
        $key = $this->keys->keyring()->find($payload->keyId);
        $known = $key instanceof QrSigningKey;

        // --- Paso 3: recalcular el HMAC en tiempo constante ------------------
        // Se firma SIEMPRE, con la clave real o con la centinela: sin esto, un
        // key_id desconocido se resolveria sin pagar el HMAC y se distinguiria
        // por tiempo de una firma invalida.
        $signatureMatches = ($key ?? $this->sentinelKey())->verifies($payload);

        $trusted = $parsed instanceof QrPayload && $known && $signatureMatches;

        // --- Paso 4: buscar por HASH del token, nunca por el token -----------
        // Tambien siempre. Con el payload centinela la consulta no encuentra
        // nada, que es justo lo que se quiere: mismo trabajo, mismo indice.
        $credential = $this->credentials->findByKeyAndSecretHash(
            $payload->keyId,
            CredentialSecret::hashOf($payload->token),
        );

        // --- Paso 5: revocacion y estado del empleado ------------------------
        // Las DOS consultas se hacen pase lo que pase, con identificadores
        // centinela cuando no hay credencial. Sin esto, «token desconocido»
        // costaria dos consultas menos que «credencial revocada», y esa
        // diferencia se mide desde fuera: es exactamente el canal que RS-03
        // cierra. La prueba `ConstantTimeRejectionTest` cuenta las consultas de
        // cada camino y falla si alguien vuelve a ahorrarselas.
        $employeeUuid = $this->employees->uuidOf($credential instanceof Credential
            ? $credential->employeeId
            : self::SENTINEL_EMPLOYEE_ID);

        $employee = $this->directory->find($employeeUuid ?? self::SENTINEL_EMPLOYEE_UUID);

        $reason = $this->rejectionReason($trusted, $credential, $employeeUuid, $employee);

        // --- Paso 6: mismo desenlace y mismo tiempo para todo rechazo --------
        if ($reason instanceof CredentialRejectionReason) {
            $this->recordRejection($reason, $employeeUuid);
            $this->padTo($startedAt);

            return CredentialResolution::rejected($reason);
        }

        /** @var string $employeeUuid Lo garantiza `rejectionReason()`: sin UUID no se llega aqui. */
        return CredentialResolution::resolved($employeeUuid);
    }

    /**
     * El motivo del rechazo, o `null` si la credencial es valida.
     *
     * Las condiciones se evaluan de la mas general a la mas concreta, que es el
     * orden del §5.2. **Este metodo no consulta nada**: recibe todo ya resuelto,
     * precisamente para que ninguna rama pueda costar mas que otra.
     */
    private function rejectionReason(
        bool $trusted,
        ?Credential $credential,
        ?string $employeeUuid,
        ?EmployeeSnapshot $employee,
    ): ?CredentialRejectionReason {
        if (! $trusted) {
            // Prefijo, formato, key_id desconocido y firma que no verifica son
            // el mismo motivo interno: el payload no lo emitio este servidor.
            return CredentialRejectionReason::INVALID_SIGNATURE;
        }

        if (! $credential instanceof Credential) {
            return CredentialRejectionReason::UNKNOWN;
        }

        if (! $credential->isActive()) {
            return CredentialRejectionReason::REVOKED;
        }

        if ($employeeUuid === null || ! $employee instanceof EmployeeSnapshot) {
            // La clave ajena impide que esto ocurra. Si ocurre, la credencial
            // apunta a nadie: se trata como desconocida y no como valida.
            return CredentialRejectionReason::UNKNOWN;
        }

        if (! $employee->canClock()) {
            // Un empleado de baja con la tarjeta todavia activa. RN-14 hara que
            // la baja revoque la credencial (Fase 2); hasta entonces, y tambien
            // despues, esta comprobacion es la que impide que fiche.
            return CredentialRejectionReason::REVOKED;
        }

        return null;
    }

    /**
     * Deja constancia del motivo real **del lado del servidor** (§5.2, paso 6).
     *
     * `warning` y no `info`: un repunte de rechazos por firma es el Gherkin «QR
     * falsificado» del doc 01 §11 y tiene que ser visible en el cuadro de mando.
     * El contador `scans_total{device,result}` lo incrementa `Attendance` al
     * escribir `scan_events`, que es quien conoce el dispositivo.
     */
    private function recordRejection(CredentialRejectionReason $reason, ?string $employeeUuid): void
    {
        // El mismo vocabulario que `scan_events.result`, para poder cruzar el log
        // con la tabla sin traducir nada a mano. La correspondencia canonica la
        // declara `Attendance\Domain\ValueObject\ScanRejectionReason`, que este
        // modulo no puede importar (ADR-025): se repite aqui, y la prueba de
        // `HmacSignatureVerifier` la ata a los valores del CHECK
        // `scan_events_chk_result`.
        $result = match ($reason) {
            CredentialRejectionReason::UNKNOWN => 'rejected_unknown',
            CredentialRejectionReason::REVOKED => 'rejected_revoked',
            CredentialRejectionReason::INVALID_SIGNATURE => 'rejected_signature',
        };

        Log::warning('credential_rejected', [
            'result' => $result,
            'reason' => $reason->value,
            // Solo cuando se sabe, y jamas el nombre (regla dura 21). En los
            // rechazos por firma no se sabe, y es correcto que no se sepa.
            'employee_uuid' => $employeeUuid,
        ]);
    }

    /**
     * Consume el tiempo que falte hasta el suelo de rechazo (RS-03).
     *
     * `hrtime()` es monotono: no lo mueve un ajuste de reloj ni un cambio de
     * hora, que es exactamente lo que no puede permitirse una medida usada para
     * decidir cuanto dormir. Si el trabajo ya tardo mas que el suelo, no se
     * duerme nada.
     */
    private function padTo(float|int $startedAt): void
    {
        $floorNs = $this->rejectionFloorMs * 1_000_000;
        $elapsedNs = hrtime(true) - $startedAt;

        if ($elapsedNs >= $floorNs) {
            return;
        }

        usleep((int) (($floorNs - $elapsedNs) / 1_000));
    }

    private function sentinelPayload(): QrPayload
    {
        return QrPayload::of(self::SENTINEL_KEY_ID, self::SENTINEL_TOKEN, self::SENTINEL_SIGNATURE);
    }

    private function sentinelKey(): QrSigningKey
    {
        return QrSigningKey::fromBase64(self::SENTINEL_KEY_ID, self::SENTINEL_SECRET);
    }
}
