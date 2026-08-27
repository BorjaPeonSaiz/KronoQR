<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Application\Port\CredentialSecretFactory;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Port\QrKeyProvider;
use App\Modules\Identity\Domain\Event\CredentialPrinted;
use App\Modules\Identity\Domain\Exception\CredentialAlreadyPrinted;
use App\Modules\Identity\Domain\Exception\CredentialAlreadyRevoked;
use App\Modules\Identity\Domain\Exception\InvalidSigningKey;
use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Identity\Domain\ValueObject\CardFormat;
use App\Modules\Identity\Domain\ValueObject\CredentialSecret;
use App\Modules\Identity\Domain\ValueObject\PrintableCard;
use App\Modules\Identity\Domain\ValueObject\QrSigningKey;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\EmployeeCardProfile;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * **El acto de acuñar el QR**: firma los tokens, dibuja el PDF y marca las
 * credenciales como impresas (RF-QR-04, RF-QR-05, ADR-034).
 *
 * Lo comparten {@see PrintCredential} y {@see PrintCredentialBatch} porque los
 * dos hacen exactamente lo mismo con distinta seleccion, y porque el orden de los
 * pasos **no es negociable**: escrito dos veces, acabaria divergiendo en uno de
 * los dos y el sintoma seria una tarjeta impresa que nadie puede usar.
 *
 * ## El orden, y por que cada paso esta donde esta
 *
 * 1. **Verificar que TODAS estan pendientes de imprimir.** Si alguna ya lo esta,
 *    `409` y **no se imprime ninguna**: el lote es todo o nada. Un lote a medias
 *    es peor que ninguno, porque nadie sabria cuales de las sesenta tarjetas de
 *    la hoja valen.
 * 2. **Resolver la clave VIGENTE** con `QrKeyProvider`. No existe «la clave de la
 *    emision»: nunca la hubo (ADR-034). Es lo que hace que una tarjeta emitida
 *    antes de una rotacion e impresa despues salga firmada con la clave nueva, en
 *    vez de con una que esa misma semana se retira (doc 02 §5.3).
 * 3. **Generar el token en memoria** con `CredentialSecretFactory` y firmarlo con
 *    `QrSigningKey::sign()`. Aqui, y en ningun otro sitio del proceso, existe el
 *    token en claro.
 * 4. **Renderizar el QR y el PDF SIN transaccion abierta.** Browsershot arranca un
 *    Chromium: es un proceso externo que puede tardar segundos y que no debe
 *    sostener bloqueos de fila sobre `credentials` mientras tanto.
 * 5. **Abrir la transaccion**, llamar a `Credential::printedWith()`, persistir con
 *    un `UPDATE ... WHERE printed_at IS NULL` cuyo recuento de filas se comprueba
 *    —si es 0, alguien imprimio en paralelo: `rollback` y `409`—, publicar
 *    `CredentialPrinted` y confirmar.
 * 6. **Devolver el PDF.**
 *
 * ## El riesgo residual, aceptado y por escrito
 *
 * Si la respuesta se pierde despues del `commit` —se corta la red, el navegador
 * cierra— el token es irrecuperable y esa credencial queda **impresa sin que
 * nadie tenga la tarjeta**. No hay forma de evitarlo sin guardar el token, que es
 * justo lo que ADR-034 prohibe. Se resuelve revocando con motivo «impresion
 * fallida» y reemitiendo, y lo que distingue este caso de «el empleado la perdio»
 * es que `delivered_at` sigue vacio. El runbook `tarjeta-perdida-o-rota.md` lo
 * describe paso a paso.
 *
 * ## No hay reimpresion
 *
 * `Credential::printedWith()` lanza `CredentialAlreadyPrinted` sobre una
 * credencial ya impresa y **no existe ningun `--force`**. «Reimprimir» solo puede
 * significar acuñar otro token, y eso mata la tarjeta que quiza ya esta en un
 * bolsillo.
 */
final readonly class MintCards
{
    public function __construct(
        private CredentialRepository $credentials,
        private QrKeyProvider $keys,
        private CredentialSecretFactory $secrets,
        private CardRenderer $renderer,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<CardToMint>  $targets
     * @param  bool  $batch  Si forma parte de una impresion por lotes. Va al evento y al asiento.
     *
     * @throws CredentialAlreadyPrinted cuando alguna ya se imprimio, tambien si ocurre en paralelo
     * @throws CredentialAlreadyRevoked cuando alguna esta revocada
     * @throws InvalidSigningKey cuando la instalacion no tiene clave
     */
    public function mint(array $targets, CardFormat $format, bool $batch, ?int $actorUserId): PrintedCards
    {
        if ($targets === []) {
            // Un lote vacio no es un error: es lo que devuelve `--pending` sobre
            // un centro que ya tiene todo impreso, y es la forma que toma su
            // idempotencia (ADR-034).
            return new PrintedCards('', $format, []);
        }

        // ---- Paso 1: todas pendientes, o ninguna se imprime -----------------
        $this->guardAllArePending($targets);

        // ---- Paso 2: la clave vigente, no la de la emision -------------------
        $key = $this->keys->keyring()->current();

        // ---- Paso 3: los tokens, en memoria y solo aqui ----------------------
        /** @var list<array{card: PrintableCard, secret: CredentialSecret, target: CardToMint}> $minted */
        $minted = [];

        foreach ($targets as $target) {
            $secret = $this->secrets->next();

            $minted[] = [
                'card' => new PrintableCard(
                    credentialUuid: $target->credential->uuid,
                    payload: $key->sign($secret->value),
                    holder: $target->holder,
                ),
                'secret' => $secret,
                'target' => $target,
            ];
        }

        // ---- Paso 4: el PDF, FUERA de la transaccion -------------------------
        $pdf = $this->renderer->render(
            array_map(static fn (array $item): PrintableCard => $item['card'], $minted),
            $format,
        );

        $now = $this->clock->now();

        // ---- Paso 5: la transaccion --------------------------------------------
        $views = $this->connection->transaction(
            fn (): array => $this->persist($minted, $key, $now, $batch, $actorUserId),
        );

        // ---- Paso 6: el PDF sale por la respuesta -----------------------------
        return new PrintedCards($pdf, $format, $views);
    }

    /**
     * Paso 1. Se comprueban **todas antes de tocar nada**, no una a una sobre la
     * marcha: si la tercera de sesenta estuviera impresa, un bucle que fuera
     * acuñando dejaria dos tokens acuñados y cincuenta y ocho sin, con el PDF
     * todavia por dibujar.
     *
     * @param  list<CardToMint>  $targets
     */
    private function guardAllArePending(array $targets): void
    {
        foreach ($targets as $target) {
            if ($target->credential->isPrinted()) {
                throw CredentialAlreadyPrinted::forCredential($target->credential->uuid);
            }

            if (! $target->credential->isActive()) {
                throw CredentialAlreadyRevoked::forCredential($target->credential->uuid);
            }
        }
    }

    /**
     * Paso 5, dentro de la transaccion.
     *
     * **Los eventos se publican aqui dentro a proposito**, igual que en la
     * emision: el listener de auditoria escribe en `audit_log` y ADR-027 exige
     * que, si ese asiento falla, la accion auditada no se confirme. Una tarjeta
     * acuñada sin rastro es peor que una tarjeta no acuñada, porque la segunda se
     * repite y la primera no se descubre.
     *
     * @param  list<array{card: PrintableCard, secret: CredentialSecret, target: CardToMint}>  $minted
     * @return list<CredentialView>
     */
    private function persist(
        array $minted,
        QrSigningKey $key,
        DateTimeImmutable $now,
        bool $batch,
        ?int $actorUserId,
    ): array {
        $views = [];

        foreach ($minted as $item) {
            $printed = $item['target']->credential->printedWith($key, $item['secret'], $now);

            if (! $this->credentials->markPrinted($printed)) {
                // Cero filas afectadas: entre el paso 1 y este `UPDATE`, otro
                // proceso imprimio esta credencial o la revoco. El `throw` deshace
                // la transaccion entera —tambien las tarjetas del lote que ya
                // habian pasado— y quien llama responde `409`. El PDF que se
                // dibujo se descarta: dibujarlo costo un Chromium, confirmarlo
                // habria costado una tarjeta duplicada.
                throw CredentialAlreadyPrinted::forCredential($printed->uuid);
            }

            $this->events->publish(new CredentialPrinted(
                credentialId: $printed->id ?? 0,
                credentialUuid: $printed->uuid,
                employeeUuid: $item['target']->holder->employeeUuid,
                keyId: $key->id,
                batch: $batch,
                actorUserId: $actorUserId,
                occurredAt: $now,
            ));

            $views[] = new CredentialView($printed, $item['target']->holder->employeeUuid);
        }

        return $views;
    }

    /**
     * Empareja credenciales con perfiles de empleado, descartando aquellas cuyo
     * titular no aparece en el directorio.
     *
     * Es un metodo de este servicio y no de cada caso de uso porque los dos
     * necesitan exactamente lo mismo, y porque «que hacer cuando falta el perfil»
     * es una decision, no un detalle: se **descarta**, no se imprime una tarjeta
     * sin nombre. RF-QR-04 dice que la tarjeta lleva nombre, departamento y
     * centro; una con el hueco en blanco no cumple el requisito y ademas gastaria
     * el unico token que esa credencial va a tener.
     *
     * @param  list<Credential>  $credentials
     * @param  array<int, EmployeeCardProfile>  $profilesByEmployeeId
     * @return list<CardToMint>
     */
    public static function pair(array $credentials, array $profilesByEmployeeId): array
    {
        $targets = [];

        foreach ($credentials as $credential) {
            $profile = $profilesByEmployeeId[$credential->employeeId] ?? null;

            if ($profile === null) {
                continue;
            }

            $targets[] = new CardToMint($credential, $profile);
        }

        return $targets;
    }
}
