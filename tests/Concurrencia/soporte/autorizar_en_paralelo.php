<?php

/**
 * Proceso auxiliar de AutorizacionConcurrenteTest: simula a OTRO administrador que,
 * en su propia conexión y transacción, bloquea el espacio, autoriza una reserva,
 * espera unos segundos y confirma. La conexión llega por variables de entorno
 * (CONC_DSN, CONC_USER, CONC_PASS) y nunca se imprime.
 *
 * Uso: php autorizar_en_paralelo.php <espacio_id> <reserva_id> <segundos>
 */
[, $espacioId, $reservaId, $segundos] = $argv;

$pdo = new PDO((string) getenv('CONC_DSN'), (string) getenv('CONC_USER'), (string) getenv('CONC_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->beginTransaction();
$pdo->prepare('SELECT id FROM espacios WHERE id = ? FOR UPDATE')->execute([(int) $espacioId]);
$pdo->prepare('UPDATE reservas SET estado_id = 5 WHERE id = ?')->execute([(int) $reservaId]);
$pdo->prepare("UPDATE reserva_detalles SET estado_detalle = 'autorizada' WHERE reserva_id = ?")->execute([(int) $reservaId]);

fwrite(STDOUT, "BLOQUEADO\n");
fflush(STDOUT);

usleep((int) ((float) $segundos * 1_000_000));

$pdo->commit();
fwrite(STDOUT, "CONFIRMADO\n");
