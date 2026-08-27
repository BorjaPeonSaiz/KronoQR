<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Una fila de la exportacion legal, sea del tipo que sea (RF-IN-05).
 *
 * **Es el idioma del puerto `LegalExportSource`.** Lo que cruza la frontera
 * hacia la aplicacion no son filas de `shift_entries` ni objetos de Eloquent,
 * sino esto: dos formas cerradas —{@see ExportedShiftEntry} y
 * {@see ExportedCorrection}— con el mismo sujeto y tipos ya validados. El
 * escritor de CSV decide como se pinta cada una; el dominio decide que se puede
 * decir.
 *
 * **Ni una sola clase con veinte campos nulos.** Una fila de correccion no tiene
 * hora de entrada y una de tramo no tiene autor: con un unico tipo, la mitad de
 * las columnas serian nulas siempre y nada impediria escribir una fila que
 * afirmara las dos cosas a la vez.
 */
interface LegalExportRecord
{
    public function type(): LegalExportRecordType;

    public function subject(): ExportedSubject;
}
