<?php
declare(strict_types=1);

/**
 * Hell, dunkel, oder wie das Gerät es hält.
 *
 * Die Wahl ist eine Einstellung des Geräts, kein Datensatz auf dem Server:
 * Wer die App auf dem Tablet dunkel mag und am Rechner hell, soll das haben
 * können, ohne dass eines das andere umstellt. Sie liegt deshalb im
 * localStorage und nicht in der Datenbank.
 *
 * Zwei Dinge stehen hier, und beide haben genau einen Grund:
 *
 *  - Das Kopfskript setzt die Wahl, BEVOR das erste Bild gezeichnet wird.
 *    Als Modul ginge das nicht - Module laufen nach dem Aufbau, und dann
 *    blitzt eine halbe Sekunde lang die helle Seite auf, bevor sie dunkel
 *    wird. Genau dafür ist ein eingebettetes Skript da.
 *  - Das Markup der drei Knöpfe, weil der Lehrkraft-Bereich sein Menü in
 *    PHP zusammensetzt und die App ihres in JavaScript. Die zweite Fassung
 *    steht in menue.js; eine Prüfung hält beide zusammen.
 */

/** Der Schlüssel im localStorage. Muss zu menue.js passen. */
const THEMA_SCHLUESSEL = 'vt-thema';

/**
 * Das Skript für den <head>.
 *
 * Bewusst winzig und ohne Abhängigkeiten: Es läuft vor allem anderen, und
 * was hier hängenbleibt, hängt die ganze Seite auf. Deshalb auch das
 * try/catch - in einem privaten Fenster wirft schon der Zugriff auf
 * localStorage.
 */
function thema_kopf_skript(): string
{
    return '<script>(function(){try{var w=localStorage.getItem('
        . json_encode(THEMA_SCHLUESSEL)
        . ');if(w==="hell"||w==="dunkel"){document.documentElement.dataset.theme='
        . '(w==="dunkel"?"dark":"light");}}catch(e){}})();</script>';
}

/**
 * Die drei Knöpfe.
 *
 * Die Auswahl steht als Markierung am gewählten Knopf, nicht als Häkchen
 * daneben: Drei Knöpfe nebeneinander, einer ist an - das liest man im
 * Vorbeigehen. Welcher, entscheidet erst das Skript; ohne eines stehen sie
 * da und tun nichts, und dann gilt die Einstellung des Geräts. Das ist die
 * Voreinstellung und für die meisten die richtige Antwort.
 */
function thema_wahl_html(): string
{
    return '<p class="mkopf klein">Farben</p>'
        . '<div class="themawahl" role="group" aria-label="Helligkeit">'
        . '<button type="button" class="themaknopf" data-thema="hell">'
        . '<span aria-hidden="true">&#9728;&#65039;</span> Hell</button>'
        . '<button type="button" class="themaknopf" data-thema="dunkel">'
        . '<span aria-hidden="true">&#127769;</span> Dunkel</button>'
        . '<button type="button" class="themaknopf" data-thema="auto">'
        . '<span aria-hidden="true">&#128241;</span> Automatisch</button>'
        . '</div>';
}
