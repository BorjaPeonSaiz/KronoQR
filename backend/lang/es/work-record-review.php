<?php

declare(strict_types=1);

/*
 * Textos de la revision de una purga no admisible del registro horario
 * (`compliance:reconcile-work-record --acknowledge`, RL-04, RS-07, ADR-057
 * enmienda del 10-10-2026 §6).
 */

return [

    'not_a_purge' => 'El asiento :id no es una purga del registro horario (retention.purge_executed). Solo se revisan esas.',
    'already_reviewed' => 'El asiento :id ya está revisado por el asiento :review. Una revisión no se repite ni se corrige: lo que haya que añadir va al informe del incidente.',
    'admissible' => 'El asiento :id es una purga admisible: la conciliación ya la cree y no hay nada que revisar.',
    'reason_too_short' => 'El motivo tiene que explicar lo investigado: al menos :min caracteres.',
    'reason_too_long' => 'El motivo admite como mucho :max caracteres.',
    'responsible_inactive' => 'La cuenta :id no es una cuenta de gestión activa con rol de administración o de RRHH. La revisión la firma una persona que responde de ella. No se ha escrito nada.',
    'missing_options' => 'Para dar por revisado el asiento :id faltan --reason y --responsible. No se ha escrito nada.',
    'incompatible_options' => '--acknowledge no se combina con --full ni con --days: o se concilia o se revisa.',
    'invalid_purge_id' => '--acknowledge tiene que ser el id del asiento, un entero positivo; «:value» no lo es. No se ha escrito nada.',
    'invalid_responsible' => '--responsible tiene que ser el id de la cuenta, un entero positivo; «:value» no lo es. No se ha escrito nada.',
    'done' => 'Asiento :review escrito: la purga :id queda revisada y deja de contarse como pendiente.',
    'explains' => 'Además vuelve a explicar los borrados de jornadas anteriores a :cutoff en el centro :site.',
    'explains_nothing' => 'No explica ningún borrado: sus defectos (:violations) no son solo el plazo.',

    'site_change_not_pending' => ':target no es una discrepancia pendiente de la zona del centro: una fila de site_timezone_changes sin su asiento firmado (change:<id>) o un site.updated sin firma (audit:<id>). La de la zona actual no se revisa: se devuelve la zona por el producto. No se ha escrito nada.',
    'site_change_already_reviewed' => ':target ya está revisada. Una revisión no se repite ni se corrige: lo que haya que añadir va al informe del incidente.',
    'site_change_incompatible' => '--acknowledge-site-change no se combina con --full, --days ni --acknowledge: o se concilia o se revisa una cosa.',
    'invalid_site_change' => '--acknowledge-site-change tiene que ser change:<id> (fila de site_timezone_changes) o audit:<id> (asiento site.updated); «:value» no lo es. No se ha escrito nada.',
    'site_change_missing_options' => 'Para dar por revisada :target faltan --reason y --responsible. No se ha escrito nada.',
    'site_change_preview' => 'Discrepancia pendiente :target del centro :site.',
    'site_change_done' => 'Asiento :review escrito: :target queda revisada y deja de contarse como pendiente.',

];
