<?php
/**
 * Joomla acfphp custom field — "EERVOL pdf-url"
 *
 * LET OP: plak in het acfphp-veld ALLEEN de code ZONDER deze <?php openingstag
 * en ZONDER dit comment-blok.
 *
 * Geeft een kale URL terug naar het juiste PDF-bestand voor het FlipBook-element.
 * Leeg als nog geen keuze gemaakt (pdf-toegang toont dan de modal).
 *
 * Artikel-ID wordt bepaald via (in volgorde):
 *   1. $item->id          — beschikbaar in Single template context
 *   2. URL-param id       — standaard com_content-route (?id=XX)
 *   3. URL-param artikel_id — aangepaste page-route (?artikel_id=XX)
 */

// ---- BEGIN: dit gedeelte plak je in het acfphp-veld ----

$app     = \Joomla\CMS\Factory::getApplication();
$session = $app->getSession();
$input   = $app->getInput();

$artikelId = (int) ($item->id ?? 0);
if ($artikelId === 0) {
    $artikelId = $input->getInt('id', 0);
}
if ($artikelId === 0) {
    $artikelId = $input->getInt('artikel_id', 0);
}
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
        ->where($db->quoteName('f.name')     . ' = ' . $db->quote($naam))
        ->where($db->quoteName('f.context')  . ' = ' . $db->quote('com_content.article'))
        ->where($db->quoteName('fv.item_id') . ' = ' . $artikelId);
    return (string) ($db->setQuery($query)->loadResult() ?? '');
};

$parseerPad = function(string $waarde): string {
    if ($waarde === '') return '';
    $decoded = json_decode($waarde, true);
    if (is_array($decoded)) {
        return (string) ($decoded['file'] ?? $decoded['src'] ?? $decoded['value'] ?? $decoded['path'] ?? '');
    }
    return $waarde;
};

$pubQuery   = $db->getQuery(true)
    ->select($db->quoteName('publish_up'))
    ->from($db->quoteName('#__content'))
    ->where($db->quoteName('id') . ' = ' . $artikelId);
$publishUp  = (string) ($db->setQuery($pubQuery)->loadResult() ?? '');
$isOpenbaar = $publishUp !== '' && strtotime($publishUp) < strtotime('-6 months');

$root        = \Joomla\CMS\Uri\Uri::root();
$urlVolledig = ($p = $parseerPad($leesVeld('leden')))              ? $root . $p : '';
$urlBeperkt  = ($p = $parseerPad($leesVeld('niet-leden-beperkt'))) ? $root . $p : '';

if ($isOpenbaar)                                        return $urlVolledig;
if ($session->get('eervol_toegang', false))             return $urlVolledig;
if ($session->get('eervol_besloten', '') === 'beperkt') return $urlBeperkt;

return ''; // Nog geen keuze → pdf-toegang toont de modal
