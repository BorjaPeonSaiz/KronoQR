# Runbook — actualizar una instalación de cliente

> **Quién lo usa:** el IT del hotel, en cada versión nueva, con `update.sh`
> delante. **Cuándo:** en ventana de baja actividad, aunque no es obligatorio:
> el fichaje no se detiene en ningún momento (§3). **Cuánto dura:** entre 5 y
> 20 minutos según el tamaño de la base de datos; casi todo es la copia previa.
>
> Este runbook cubre lo que el script no puede decidir por ti: cómo preparar el
> paquete, qué significa cada salida, y qué hacer **a mano** en el único caso
> que lo exige (salida `5`). Lo que hace el script paso a paso está en su
> cabecera (`./update.sh --help`) y en el informe que deja en el servidor.

---

## 1. Lo primero: no estás en el sitio equivocado

Si has llegado aquí porque `install.sh` ha salido con **código 3** diciendo «se
ha encontrado una instalación previa», es correcto y es deliberado. **El
instalador no se instala encima de un registro horario**, que hay que conservar
cuatro años por ley. Para pasar a una versión nueva se usa `update.sh`.

Y si lo que quieres es saber **desde qué versiones** se puede saltar a la del
paquete que tienes, sin tocar nada:

```bash
./update.sh --supported-sources      # versiones desde las que se actualiza directo
./update.sh --chain 2.1.0            # qué intermedias se aplicarían desde la 2.1.0
```

La regla es la del fabricante (doc 02 §11.6.5): **la versión menor vigente y
las dos anteriores** se actualizan directamente. Desde una más antigua, el
script te dice a cuál ir primero y no intenta el salto. La matriz es un dato
del paquete, `versions.txt`; no se edita.

---

## 2. Preparar la actualización (5 minutos, sin tocar nada)

1. **Descomprime el paquete nuevo AL LADO del actual, nunca encima.**

   ```bash
   cd /opt
   tar xzf kronoqr-2.2.0.tar.gz          # crea /opt/kronoqr-2.2.0
   ls /opt                                # kronoqr-2.1.0  kronoqr-2.2.0
   ```

   El directorio de la versión actual **es tu vuelta atrás manual** (§5): su
   `docker-compose.yml` y su `.env` son lo que relanza la versión anterior si
   todo lo demás fallara. No lo borres hasta la siguiente actualización.

   > Si lo descomprimiste encima, el script lo detecta, avisa y sigue: funciona,
   > pero la vuelta atrás tendrá que usar el compose nuevo con las imágenes
   > antiguas. La próxima vez, al lado.

2. **Lee qué cambia.** El paquete trae `docs/CHANGELOG.md`; la sección de la
   versión nueva es lo que hay que leer antes de reservar la ventana. Si trae
   claves nuevas en `.env.example`, el script te las enumera en el paso 1 y
   cada una usa su valor de serie hasta que decidas otro
   ([`configuracion.md`](../cliente/configuracion.md)).

3. **Sin salida a internet:** carga antes las imágenes de la versión nueva,
   igual que al instalar ([`instalacion.md`](../cliente/instalacion.md) §7):

   ```bash
   docker load -i imagenes-2.2.0.tar
   ```

4. **Comprueba sin tocar:**

   ```bash
   cd /opt/kronoqr-2.2.0
   sudo ./update.sh --check-only
   ```

   Sale `0` si todo está listo y `2` con la lista de lo que falta, y qué hacer
   con cada cosa. Lo que comprueba, en orden: que el paquete está entero, que
   la versión instalada está en la matriz, que hay espacio para la copia **y**
   para la migración, que los cuatro servicios están sanos y las sondas
   responden, que la clave de cifrado de las copias está en el `.env`, y **que
   la cadena de auditoría está íntegra antes de tocar nada** (§4).

5. **Ejecútalo dentro de `tmux` o `screen`** (o con `nohup`). Si la sesión SSH
   se corta a mitad, el script atrapa la señal y deshace solo, pero el mensaje
   final se iría con la conexión y solo quedaría el informe en el servidor.

---

## 3. Actualizar

```bash
cd /opt/kronoqr-2.2.0
sudo ./update.sh
```

Lo que verás, y lo que significa cada paso:

| Paso | Qué hace | Qué ve la plantilla |
| --- | --- | --- |
| 1 · Precondiciones | Lo mismo que `--check-only`, incluida la ruta de gestión que debe responder `401` sin sesión: es lo que el paso 5 y la vuelta atrás exigirán, y tiene que ser verdad ya. Si algo falla, sale `2` y no ha tocado nada | Nada |
| 2 · Mantenimiento | El panel, el portal y la API de gestión responden «en mantenimiento» (503). `horizon` y `scheduler` se paran para que nada escriba | **Los quioscos siguen fichando**: confirman en local y encolan. Es invisible para quien ficha |
| 3 · Copia previa | Copia lógica cifrada **y verificada** con la versión actual. **Bloqueante**: si falla, sale `2`, retira el mantenimiento y no ha tocado nada. No hay bandera para saltárselo | Igual |
| 4 · Migraciones | Relanza PostgreSQL y Redis con las imágenes nuevas y aplica las migraciones **versión a versión**, con un punto de control entre cada una: `PUNTO DE CONTROL 2.2.0 alcanzado: 3 migraciones aplicadas en 4 s` | Igual |
| 5 · Arranque y verificación | Arranca la aplicación nueva **sin borde** y la comprueba desde dentro: sondas, versión, cadena de auditoría, restricciones de RN-01 y RN-02. Solo si todo pasa arranca Nginx y los procesos de fondo, y vuelve a comprobar por loopback | Sigue encolando hasta que Nginx vuelve |
| 6 · Vuelta atrás | Solo si el 4 o el 5 fallan: restaura la copia del paso 3 y relanza la versión anterior, sin preguntar (§5) | Igual: nada de lo encolado se pierde |
| 7 · Informe | `BACKUP_PATH/reports/update-<fecha>.log`, siempre, también tras una vuelta atrás. Al lado, `update-<fecha>.detalle.log` con la salida cruda (migraciones, copia, restauración, logs): **solo root, puede llevar datos personales** | — |

**Por qué el mantenimiento va antes de la copia**, y no al revés como lo
enumera el plan: un fichaje aceptado *entre* la copia y el mantenimiento
existiría en la base pero no en la copia, y el quiosco ya lo habría sacado de
su cola porque el servidor lo confirmó. Una vuelta atrás lo perdería. Con el
mantenimiento primero, todo lo que ocurre durante la ventana sigue en las
colas de los quioscos y entra después, gane o pierda la actualización.

**Al terminar** (salida `0`):

- Los quioscos sincronizan lo encolado. Cada fichaje conserva **su hora real**
  (`occurred_at`); la hora de recepción será posterior, y es correcto. Si la
  ventana superó el umbral de retraso, la bandeja mostrará incidencias de
  sincronización: **no son un fallo**, son el sistema diciendo que hubo una
  ventana.
- La copia previa queda en `BACKUP_PATH/daily/` con la retención normal.
- El directorio de la versión anterior sigue ahí. Consérvalo hasta la
  siguiente actualización; su `.env` lleva los mismos secretos que el nuevo.

---

## 4. Códigos de salida

La tabla es **la misma de los cinco scripts**, publicada en
[`operacion.md`](../cliente/operacion.md) §8. Lo que significan aquí:

| Código | Significa aquí | Qué hacer |
| --- | --- | --- |
| `0` | Actualizado y verificado | Leer el informe. Nada más |
| `1` | Uso incorrecto. Nada tocado | `./update.sh --help` |
| `2` | **Una precondición no se cumple, o la copia previa ha fallado.** La instalación no se ha tocado y sigue en su versión; si ya estaba en mantenimiento, se ha retirado | La línea «Que hacer» de cada `[FALLA]`. Tres merecen párrafo: versión de origen fuera de la matriz (§1), cadena de auditoría rota (§4 bis) y copia fallida ([`restaurar-backup.md`](restaurar-backup.md) §2) |
| `3` | **Ya está en la versión de destino**, o no hay instalación que actualizar. Nada tocado | Nada. Si de verdad no hay instalación, lo que quieres es `install.sh` |
| `4` | **Falló y volvió a la versión anterior.** Copia restaurada, versión anterior en marcha y verificada | Enviar el informe al fabricante **antes** de reintentar: dice en qué paso y por qué |
| `5` | **Falló y la vuelta atrás quedó incompleta.** Nada más se toca | §5. Es el único código que exige a una persona delante |
| `6` | **No lo usa `update.sh`**: toda verificación fallida deshace (RF-PD-10). Si lo ves, es de otro script | — |

### 4 bis. «La cadena de auditoría NO está íntegra» (salida `2`)

El script verifica `compliance:verify-audit-chain` **antes** de tocar nada. Si
falla, no es un problema de la actualización: es un **incidente de seguridad**
que ya existía, y actualizar lo taparía —después nadie podría distinguir si la
rompió la actualización—. Sigue
[`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md), preserva la
evidencia, y **no actualices** hasta resolverlo.

---

## 5. Vuelta atrás a mano (solo con salida `5`)

Con `4` no hay nada que hacer: el script ya volvió. Con `5`, el script se ha
detenido **sin tocar nada más** y ha impreso las órdenes exactas con las rutas
reales de tu servidor. **Lee primero cuál de los dos casos es**, porque las
órdenes son distintas:

**Caso A — «solo ha quedado a medias el modo mantenimiento».** Falló algo
antes de la copia previa (pasos 2 o 3) y no se pudo retirar el mantenimiento.
La instalación sigue en su versión **con todos sus datos** y **no hay ninguna
copia que restaurar**: restaurar la de anoche borraría los fichajes del día.
Lo único que hay que hacer:

```bash
ACTUAL=/opt/kronoqr-2.1.0
sudo docker compose --env-file $ACTUAL/.env -f $ACTUAL/docker-compose.yml exec -T app php artisan up
sudo docker compose --env-file $ACTUAL/.env -f $ACTUAL/docker-compose.yml up -d horizon scheduler
curl -k https://127.0.0.1/api/v1/auth/me     # 401 = atiende; 503 = sigue en mantenimiento
```

**Caso B — la vuelta atrás con la copia quedó incompleta.** Son estas órdenes,
en este orden, y cada una se puede repetir:

```bash
# Rutas de ejemplo: el mensaje del script trae las tuyas.
NUEVO=/opt/kronoqr-2.2.0
ANTERIOR=/opt/kronoqr-2.1.0
COPIA=/var/backups/fichaje/daily/kronoqr-20260907T031500Z.dump.enc   # la del informe

# 1. Parar lo que escribe (postgres se queda)
sudo docker compose --env-file $NUEVO/.env -f $NUEVO/docker-compose.yml stop app horizon scheduler reverb nginx

# 2. Restaurar la copia previa. Restaura en una base NUEVA y solo al final
#    intercambia los nombres; la base fallida se conserva 7 días como
#    <base>_pre_restore_<marca> para el diagnóstico.
sudo docker compose --env-file $NUEVO/.env -f $NUEVO/docker-compose.yml run --rm --no-deps app \
  bash /opt/kronoqr/scripts/restore.sh --file $COPIA --yes

# 3. Relanzar la versión anterior desde SU directorio (su compose, su .env,
#    su IMAGE_TAG). --remove-orphans retira lo que la versión nueva creó.
sudo docker compose --env-file $ANTERIOR/.env -f $ANTERIOR/docker-compose.yml up -d --remove-orphans

# 4. Comprobar: la versión que publica la sonda es la anterior
curl -k https://127.0.0.1/api/v1/health
curl -k https://127.0.0.1/api/v1/ready
sudo docker compose --env-file $ANTERIOR/.env -f $ANTERIOR/docker-compose.yml exec -T app php artisan compliance:verify-audit-chain
```

Si `restore.sh` se niega por **conexiones abiertas** (salida `3`), algún
contenedor de aplicación sigue en pie: `docker ps` y párralo. Si sale `2`, la
copia no se descifra o no se lee: prueba con la anterior (`backup.sh list`) y
lee [`restaurar-backup.md`](restaurar-backup.md) §6, que tiene los tiempos que
caben en el RTO de 4 h.

**Mientras tanto los quioscos siguen fichando**: encolan en local (regla dura
19). Nada de lo que ocurra en la ventana se pierde si la base que acaba viva es
la restaurada, porque el servidor no confirmó ningún fichaje durante ella.

Cuando la versión anterior responda, genera el paquete de diagnóstico y abre un
caso al fabricante adjuntando el **informe** de `BACKUP_PATH/reports/`
(`update-<fecha>.log`). **El paquete va anonimizado por defecto** y el informe
no lleva secretos ni datos personales. El **detalle técnico**
(`update-<fecha>.detalle.log`) es otra cosa: es solo de root, lleva la salida
cruda de migraciones, copia, restauración y logs, y **puede contener datos
personales** (un `DETAIL: Failing row contains (...)` de PostgreSQL, por
ejemplo). Revísalo antes de enviarlo, y envíalo solo si el fabricante lo pide.

---

## 6. Lo que no cambia nunca al actualizar

- **El `.env` con tus secretos.** El actualizador lo copia al paquete nuevo tal
  cual (0600) y solo cambia `IMAGE_TAG`. No regenera ningún secreto.
- **Los datos.** Las migraciones amplían el esquema; ninguna borra registro
  horario. `audit_log` es solo-append y su cadena se verifica antes y después.
- **La licencia.** Una licencia caducada o inválida **no impide actualizar**:
  dejaría al cliente sin correcciones de seguridad sobre su registro legal
  (ADR-019). El estado de la licencia se anota en el informe, nada más.
- **El fichaje.** Ni durante la actualización ni si falla.

---

## 7. Salto de versión mayor

De una serie `2.x` a una `3.x` el script **no salta**: te remite a la última de
tu serie y a la ventana de migración que el fabricante anuncia con antelación
(doc 02 §11.6.5). Cuando exista, las instrucciones del salto llegarán en el
paquete de la primera versión de la serie nueva, en esta misma sección.

Tampoco cubre hoy un cambio de versión **mayor de PostgreSQL** (17 → 18): las
imágenes del producto fijan la 17 y una versión que la cambie traerá su propio
procedimiento de `pg_upgrade` en este runbook antes de publicarse.

---

## 8. Para el fabricante: cómo se prueba esto

La etapa ⑧b de la CI (`update`) instala la versión anterior, siembra datos,
actualiza, comprueba idempotencia, **inyecta un fallo al arrancar la versión
nueva y exige la vuelta atrás automática** con los conteos intactos, reintenta,
y restaura la copia previa en una base limpia. Con una licencia inválida a
propósito. Corre en `main`, en cada etiqueta y a mano. Los detalles de qué
versión hace de «anterior» mientras no exista una etiqueta con instalador
están en el propio job.
