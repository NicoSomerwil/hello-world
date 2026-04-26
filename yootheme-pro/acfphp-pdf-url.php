<?php
/**
 * Joomla acfphp custom field — "EERVOL pdf-url"
 *
 * LET OP: plak in het acfphp-veld ALLEEN de code ZONDER deze <?php openingstag
 * en ZONDER dit comment-blok.
 *
 * Kopieer dus alles vanaf "$app = ..." hieronder tot het einde van het bestand.
 *
 * Dit veld geeft een kale URL terug naar het juiste PDF-bestand,
 * gebaseerd op de sessie-status en of de editie openbaar is.
 * Gebruik deze waarde als bron in het YOOtheme Pro FlipBook-element.
 *
 * Geeft lege string terug als:
 *   - artikelId onbekend
 *   - nog geen keuze gemaakt (pdf-toegang toont dan de modal)
 */

// ---- BEGIN: dit gedeelte plak je in het acfphp-veld ----

$app     = \Joomla\CMS\Factory::getApplication();
$session = $app->getSession();

$artikelId = (int) ($item->id ?? 0);
if ($artikelId === 0) return '';

$db = \Joomla\CMS\Factory::getDbo();

$leesVeld = function(string $naam) use ($db, $artikelId): string {
    $query = $db->getQuery(true)
        ->select($db->quoteName('fv.value'))
        ->from($db->quoteName('#__fields_values', 'fv'))
        ->join(
            'INNER',
            $db->quoteName('#__fields', 'f')
                . ' ON ' . $db->quoteName('f.id') . ' = ' . $db->quoteName('fv.field_id')
        )
        ->where($db->quoteName('f.name')    . ' = ' . $db->quote($naam))
        ->where($db->quoteName('f.context') . ' = ' . $db->quote('com_content.article'))
        ->where($db->quoteName('fv.item_id') . ' = ' . $artikelId);

    return (string) ($db->setQuery($query)->loadResult() ?? '');
};

// Document-velden slaan JSON op: {"file":"images/eervol/...","linktext":"..."}
$parseerPad = function(string $waarde): string {
    if (empty($waarde)) return '';
    $decoded = json_decode($waarde, true);
    if (is_array($decoded)) {
        return (string) ($decoded['file'] ?? $decoded['src'] ?? $decoded['value'] ?? $decoded['path'] ?? '');
    }
    return $waarde;
};

$isOpenbaar  = $leesVeld('is-openbaar') === '1';
$root        = \Joomla\CMS\Uri\Uri::root();
$urlVolledig = ($p = $parseerPad($leesVeld('leden')))              ? $root . $p : '';
$urlBeperkt  = ($p = $parseerPad($leesVeld('niet-leden-beperkt'))) ? $root . $p : '';

// Historische editie → altijd volledige PDF
if ($isOpenbaar) return $urlVolledig;

// Recente editie — juiste code ingevoerd
if ($session->get('eervol_toegang', false)) return $urlVolledig;

// Recente editie — besloten voor beperkt
if ($session->get('eervol_besloten', '') === 'beperkt') return $urlBeperkt;

// Nog geen keuze → geef leeg terug (pdf-toegang modal regelt dit)
return '';
