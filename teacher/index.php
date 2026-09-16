<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

/*
 * Die Kursliste gibt es nicht mehr.
 *
 * Sie war eine zweite Sicht auf dieselben Daten: Kurse gehoeren zu Klassen,
 * und eine Klasse hat drei bis fuenf davon. Eine eigene Seite, die alle
 * Kurse der Schule untereinander auffuehrt, zwang dazu, in jeder Zeile die
 * Klasse mitzulesen - und wer einen Kurs anlegen wollte, musste die Klasse
 * in einem Auswahlfeld wieder heraussuchen, obwohl er gerade von ihr kam.
 * Beides steht jetzt in der Klasse selbst.
 *
 * Die Datei bleibt trotzdem stehen, aus zwei Gruenden: /teacher/ landet
 * ohne Dateinamen hier, und das Abmelde-Formular in der Leiste schickt
 * hierher. Wer ein Lesezeichen auf die alte Liste hat, landet jetzt bei den
 * Klassen statt auf einer 404.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['teacher_logout'])) {
    teacher_csrf_check();
    logout_user();
    teacher_redirect('index.php');
}

// Ohne Anmeldung zeigt teacher_require() das Anmeldeformular - deshalb erst
// danach weiterleiten, sonst landet man in einer Schleife.
teacher_require();
teacher_redirect('classes.php');
