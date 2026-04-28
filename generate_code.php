<?php

/**
 * Hulpscript om de bestandsnamen van EERVOL-PDF's vooraf te berekenen.
 *
 * Gebruik: voer de exacte artikeltitel en het editienummer in.
 * De output zijn de twee bestandsnamen die je voor de PDF-uploads gebruikt.
 *
 * De SECRET_KEY moet gelijk zijn aan die in de acfphp code van het 'code'-veld
 * én aan die in het eervolslot-plugin (wachtwoord staat daar los van; de key
 * gaat alleen over de bestandsnamen).
 */

define('SECRET_KEY', 'v3Ry$3cr3t!K3y-Ch4ng3-M3-1n-Pr0d');

function genereer_bestandsnaam(string $titel, int $nummer, string $versie): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    // Voeg de versie toe aan de invoer zodat volledig en beperkt totaal
    // verschillende codes krijgen, ook al is de titel identiek.
    $hash  = hash_hmac('sha256', $titel . ':' . $versie, SECRET_KEY, binary: true);
    $code  = '';
    $len   = strlen($alphabet);
    for ($i = 0; $i < 10; $i++) {
        $code .= $alphabet[ord($hash[$i]) % $len];
    }
    return 'E' . $nummer . '_' . $code . '.pdf';
}

// --- Invoer ---
$voorbeelden = [
    ['titel' => 'EERVOL E-18 |', 'nummer' => 18],
    ['titel' => 'EERVOL E-19 |', 'nummer' => 19],
    ['titel' => 'EERVOL E-20 |', 'nummer' => 20],
];

printf("%-20s %-30s %-30s\n", 'Editie', 'Volledig (leden)', 'Beperkt (niet-leden)');
printf("%s\n", str_repeat('-', 82));

foreach ($voorbeelden as $e) {
    printf(
        "%-20s %-30s %-30s\n",
        $e['titel'],
        genereer_bestandsnaam($e['titel'], $e['nummer'], 'volledig'),
        genereer_bestandsnaam($e['titel'], $e['nummer'], 'beperkt')
    );
}
