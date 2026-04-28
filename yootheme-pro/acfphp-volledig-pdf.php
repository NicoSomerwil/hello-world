<?php
/**
 * Joomla acfphp custom field — "EERVOL PDF-toegang"
 *
 * LET OP: plak in het acfphp-veld ALLEEN de code ZONDER deze <?php openingstag
 * en ZONDER dit comment-blok.
 *
 * Kopieer dus alles vanaf "$app = ..." hieronder tot het einde van het bestand.
 *
 * Artikel-ID wordt bepaald via (in volgorde):
 *   1. $item->id          — beschikbaar in Single template context
 *   2. URL-param id       — standaard com_content-route (?id=XX)
 *   3. URL-param artikel_id — aangepaste page-route (?artikel_id=XX)
 *
 * Voor Switcher Pro / Page-aanpak: voeg ?artikel_id=XX toe aan elke kaartlink.
 *
 * POST-verwerking (sessie zetten + redirect) wordt gedaan door de eervolslot-plugin.
 */

// ---- BEGIN: dit gedeelte plak je in het acfphp-veld ----

$app     = \Joomla\CMS\Factory::getApplication();
$session = $app->getSession();
$input   = $app->getInput();

// Artikel-ID: probeer achtereenvolgens drie bronnen
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

// Document-velden slaan JSON op: {"file":"images/eervol/...","linktext":"..."}
$parseerPad = function(string $waarde): string {
    if ($waarde === '') return '';
    $decoded = json_decode($waarde, true);
    if (is_array($decoded)) {
        return (string) ($decoded['file'] ?? $decoded['src'] ?? $decoded['value'] ?? $decoded['path'] ?? '');
    }
    return $waarde;
};

// Publicatiedatum ophalen voor de 6-maanden-regel (vervangt het is-openbaar veld)
$pubQuery  = $db->getQuery(true)
    ->select($db->quoteName('publish_up'))
    ->from($db->quoteName('#__content'))
    ->where($db->quoteName('id') . ' = ' . $artikelId);
$publishUp  = (string) ($db->setQuery($pubQuery)->loadResult() ?? '');
$isOpenbaar = $publishUp !== '' && strtotime($publishUp) < strtotime('-6 months');

$root        = \Joomla\CMS\Uri\Uri::root();
$urlVolledig = ($p = $parseerPad($leesVeld('leden')))              ? $root . $p : '';
$urlBeperkt  = ($p = $parseerPad($leesVeld('niet-leden-beperkt'))) ? $root . $p : '';

// -----------------------------------------------------------------------
// Historische editie (ouder dan 6 maanden) → volledige PDF altijd vrij
// -----------------------------------------------------------------------
if ($isOpenbaar) {
    if (empty($urlVolledig)) return '';
    return '<a href="' . htmlspecialchars($urlVolledig, ENT_QUOTES) . '"
               class="uk-button uk-button-primary" target="_blank" rel="noopener">
               <span uk-icon="file-pdf"></span>&nbsp; Volledige editie bekijken
            </a>';
}

// -----------------------------------------------------------------------
// Recente editie — juiste inlogcode al ingevoerd deze sessie
// -----------------------------------------------------------------------
if ($session->get('eervol_toegang', false)) {
    if (empty($urlVolledig)) return '';
    return '<a href="' . htmlspecialchars($urlVolledig, ENT_QUOTES) . '"
               class="uk-button uk-button-primary" target="_blank" rel="noopener">
               <span uk-icon="file-pdf"></span>&nbsp; Volledige editie bekijken
            </a>';
}

// -----------------------------------------------------------------------
// Recente editie — eerder leeg/fout ingevuld → beperkte PDF + retry-link
// -----------------------------------------------------------------------
if ($session->get('eervol_besloten', '') === 'beperkt') {
    $resetUri = clone \Joomla\CMS\Uri\Uri::getInstance();
    $resetUri->setVar('eervol_reset', '1');
    $html = '';

    if ($urlBeperkt) {
        $html .= '<p class="uk-text-small uk-text-muted uk-margin-remove-bottom">
                      Dit is de beperkte versie van deze editie.
                  </p>
                  <a href="' . htmlspecialchars($urlBeperkt, ENT_QUOTES) . '"
                     class="uk-button uk-button-default" target="_blank" rel="noopener">
                     <span uk-icon="file-pdf"></span>&nbsp; Beperkte editie bekijken
                  </a>';
    }

    $html .= '<p class="uk-text-small uk-text-muted uk-margin-small-top">
                  <a href="' . htmlspecialchars($resetUri->toString(), ENT_QUOTES) . '">
                      <span uk-icon="refresh"></span>&nbsp; Toch een inlogcode? Klik hier.
                  </a>
              </p>';

    return $html;
}

// -----------------------------------------------------------------------
// Eerste bezoek — popup automatisch openen
// -----------------------------------------------------------------------
$modalId = 'eervol-slot-' . $artikelId;

return '
<div id="' . $modalId . '" uk-modal="bg-close: false; esc-close: false">
    <div class="uk-modal-dialog uk-modal-body">

        <h3 class="uk-modal-title">EERVOL &mdash; Volledige editie</h3>

        <p>Vul hier de inlogcode in om de volledige editie te bekijken.<br>
           <span class="uk-text-small uk-text-muted">
               Heb je geen inlogcode? Laat het veld leeg en klik op <strong>OK</strong>.
               Je ziet dan de beperkte versie.
           </span>
        </p>

        <form method="post" action="">
            <input type="hidden" name="eervol_form_submitted" value="1">

            <div class="uk-margin">
                <input class="uk-input"
                       type="password"
                       name="eervol_pw"
                       placeholder="Inlogcode (optioneel)"
                       autocomplete="off"
                       style="padding: 6px 12px; font-size: 1rem; height: 42px;">
            </div>

            <div class="uk-flex uk-flex-between uk-flex-middle">
                <button class="uk-button uk-button-primary" type="submit">
                    OK
                </button>
                <span class="uk-text-small uk-text-muted">
                    Leeg laten = beperkte versie
                </span>
            </div>
        </form>

    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        UIkit.modal(document.getElementById(' . json_encode($modalId) . ')).show();
    });
</script>';
