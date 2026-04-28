<?php
/**
 * Joomla custom field 'code' — type: acfphp
 *
 * Plak deze code in het acfphp-veld 'code' in Joomla (Componenten → Velden → Code).
 * Het veld toont de twee bestandsnamen die je voor de PDF-uploads gebruikt.
 *
 * Volledig (leden)     → upload deze PDF en koppel hem in het veld 'Leden'
 * Beperkt (niet-leden) → upload deze PDF en koppel hem in het veld 'NIET-leden (beperkt)'
 *
 * De twee codes zijn cryptografisch onafhankelijk: het kennen van de ene
 * bestandsnaam geeft geen enkele aanwijzing over de andere.
 */

$secretKey = 'v3Ry$3cr3t!K3y-Ch4ng3-M3-1n-Pr0d'; // zelfde als in generate_code.php
$alphabet  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
$titel     = $item->title ?? '';
$nummer    = (int) ($item->jcfields['nummer']->rawvalue ?? 0);

if (empty($titel) || $nummer === 0) {
    return '(bewaar het artikel eerst om de bestandsnamen te zien)';
}

$maak = function (string $invoer) use ($secretKey, $alphabet): string {
    $hash = hash_hmac('sha256', $invoer, $secretKey, true);
    $code = '';
    $len  = strlen($alphabet);
    for ($i = 0; $i < 10; $i++) {
        $code .= $alphabet[ord($hash[$i]) % $len];
    }
    return $code;
};

$prefix       = 'E' . $nummer . '_';
$naamVolledig = $prefix . $maak($titel . ':volledig') . '.pdf';
$naamBeperkt  = $prefix . $maak($titel . ':beperkt')  . '.pdf';

return '<strong>Volledig&nbsp;(leden):</strong> '     . $naamVolledig . '<br>'
     . '<strong>Beperkt&nbsp;(niet-leden):</strong> ' . $naamBeperkt;
