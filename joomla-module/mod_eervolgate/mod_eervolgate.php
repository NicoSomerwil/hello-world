<?php
/**
 * mod_eervolgate — EERVOL PDF-toegangspoort
 *
 * Werkt op elke Joomla-pagina zolang het artikel-ID beschikbaar is via:
 *   - ?id=XX        (standaard com_content artikel-route)
 *   - ?artikel_id=XX (aangepaste page-route, zet dit in de link vanuit Switcher Pro)
 *
 * De module verwerkt de inlogcode-POST zelf en heeft geen aparte eervolslot-plugin nodig.
 *
 * Installatie:
 *   1. Zip deze map (mod_eervolgate/) en installeer via Joomla → Extensies → Installeren.
 *   2. Ga naar Modules → Nieuw → kies EERVOL Toegangspoort.
 *   3. Stel de KVEO inlogcode en het aantal maanden in.
 *   4. Wijs de module toe aan de juiste pagina's (via Menutoewijzing of een YOOtheme Pro Module-element).
 *   5. Publiceer.
 *
 * YOOtheme Pro: voeg een 'Module'-element toe aan de pagina en selecteer deze module op naam.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Module\Helper as ModuleHelper;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

$app     = Factory::getApplication();
$session = $app->getSession();
$input   = $app->getInput();

// Bepaal artikel-ID: eerst standaard com_content-parameter, dan aangepast
$artikelId = $input->getInt('id', 0);
if ($artikelId === 0) {
    $artikelId = $input->getInt('artikel_id', 0);
}
if ($artikelId === 0) {
    return; // Geen artikel-ID → module toont niets
}

// -----------------------------------------------------------------------
// Reset-verzoek: verwijder 'besloten'-sessie, redirect naar dezelfde pagina
// -----------------------------------------------------------------------
if ($input->getInt('eervol_reset', 0) === 1) {
    $session->clear('eervol_besloten');
    $uri = Uri::getInstance();
    $uri->delVar('eervol_reset');
    $app->redirect($uri->toString());
    return;
}

// -----------------------------------------------------------------------
// Verwerk inlogcode-formulier (POST)
// -----------------------------------------------------------------------
if ($input->getMethod() === 'POST' && $input->post->getInt('eervol_form_submitted', 0) === 1) {

    Session::checkToken('post') or die('Ongeldige aanvraag.');

    $ingevoerd  = trim($input->post->getString('eervol_pw', ''));
    $juisteCode = trim((string) $params->get('inlogcode', ''));

    if ($juisteCode !== '' && $ingevoerd !== '' && hash_equals($juisteCode, $ingevoerd)) {
        $session->set('eervol_toegang', true);
        $session->clear('eervol_besloten');
    } else {
        $session->set('eervol_besloten', 'beperkt');
    }

    // Redirect naar dezelfde pagina (verwijdert POST-data uit URL)
    $app->redirect(Uri::getInstance()->toString());
    return;
}

// -----------------------------------------------------------------------
// Haal publicatiedatum op en bepaal openbaar-regel (standaard 6 maanden)
// -----------------------------------------------------------------------
$db = Factory::getDbo();

$query = $db->getQuery(true)
    ->select($db->quoteName('publish_up'))
    ->from($db->quoteName('#__content'))
    ->where($db->quoteName('id') . ' = ' . $artikelId);
$publishUp = (string) ($db->setQuery($query)->loadResult() ?? '');

$maanden    = max(1, (int) $params->get('maanden_openbaar', 6));
$isOpenbaar = $publishUp !== '' && strtotime($publishUp) < strtotime("-{$maanden} months");

// -----------------------------------------------------------------------
// Lees custom velden uit de database
// -----------------------------------------------------------------------
$leesVeld = function (string $naam) use ($db, $artikelId): string {
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

// Document-velden slaan JSON op: {"file":"images/eervol/...","linktext":"..."}
$parseerPad = function (string $waarde): string {
    if ($waarde === '') return '';
    $decoded = json_decode($waarde, true);
    if (is_array($decoded)) {
        return (string) ($decoded['file'] ?? $decoded['src'] ?? $decoded['value'] ?? $decoded['path'] ?? '');
    }
    return $waarde;
};

$root        = Uri::root();
$urlVolledig = ($p = $parseerPad($leesVeld('leden')))              ? $root . $p : '';
$urlBeperkt  = ($p = $parseerPad($leesVeld('niet-leden-beperkt'))) ? $root . $p : '';

// -----------------------------------------------------------------------
// Geef variabelen door aan de template
// -----------------------------------------------------------------------
$heeftToegang = (bool) $session->get('eervol_toegang', false);
$besloten     = (string) $session->get('eervol_besloten', '');
$modalId      = 'eervol-slot-' . $artikelId;

require ModuleHelper::getLayoutPath('mod_eervolgate', $params->get('layout', 'default'));
