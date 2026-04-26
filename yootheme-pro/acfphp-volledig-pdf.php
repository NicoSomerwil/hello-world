<?php
/**
 * Joomla acfphp custom field — "EERVOL PDF-toegang"
 *
 * LET OP: plak in het acfphp-veld ALLEEN de code ZONDER deze <?php openingstag
 * en ZONDER dit comment-blok. Het acfphp-veld voert de code zelf uit als PHP;
 * een extra <?php tag veroorzaakt "syntax error, unexpected token '<'".
 *
 * Kopieer dus alles vanaf "$app = ..." hieronder tot het einde van het bestand.
 *
 * Gedrag per situatie:
 *
 *   Historische editie (is-openbaar = 1)
 *   → Volledige PDF knop direct zichtbaar, geen prompt.
 *
 *   Recente editie — nog geen keuze gemaakt (eerste bezoek)
 *   → Popup verschijnt automatisch met de vraag om de inlogcode.
 *      Juiste code → sessie 'eervol_toegang' → redirect → volledige PDF knop.
 *      Leeg of fout → sessie 'eervol_besloten=beperkt' → redirect → beperkte PDF knop.
 *
 *   Recente editie — sessie 'eervol_toegang'
 *   → Volledige PDF knop direct, geen popup meer.
 *
 *   Recente editie — sessie 'eervol_besloten=beperkt'
 *   → Beperkte PDF knop + link "Toch een inlogcode?" om de prompt opnieuw te tonen.
 *
 * Eén keer de juiste code invoeren ontgrendelt alle recente edities voor dit bezoek.
 */

// ---- BEGIN: dit gedeelte plak je in het acfphp-veld ----

$app     = \Joomla\CMS\Factory::getApplication();
$session = $app->getSession();

$isOpenbaar = ($item->jcfields['is-openbaar']->rawvalue ?? '0') === '1';

// Document-velden slaan JSON op: {"file":"images/eervol/...","linktext":"..."}
// Deze functie extraheert het bestandspad uit JSON of geeft de waarde terug als string.
$parseerPad = function(string $waarde): string {
    if (empty($waarde)) return '';
    $decoded = json_decode($waarde, true);
    if (is_array($decoded)) {
        return (string) ($decoded['file'] ?? $decoded['src'] ?? $decoded['value'] ?? $decoded['path'] ?? '');
    }
    return $waarde;
};

$root        = \Joomla\CMS\Uri\Uri::root();
$padVolledig = $parseerPad($item->jcfields['leden']->rawvalue ?? '');
$urlVolledig = $padVolledig ? $root . $padVolledig : '';
$padBeperkt  = $parseerPad($item->jcfields['niet-leden-beperkt']->rawvalue ?? '');
$urlBeperkt  = $padBeperkt  ? $root . $padBeperkt  : '';

// -----------------------------------------------------------------------
// Historische editie → volledige PDF altijd vrij
// -----------------------------------------------------------------------
if ($isOpenbaar) {
    if (empty($urlVolledig)) return '';
    return '<a href="' . htmlspecialchars($urlVolledig, ENT_QUOTES) . '"
               class="uk-button uk-button-primary" target="_blank" rel="noopener">
               <span uk-icon="file-pdf"></span>&nbsp; Volledige editie bekijken
            </a>';
}

// -----------------------------------------------------------------------
// Recente editie — juiste code al ingevoerd deze sessie
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
    $resetUrl = \Joomla\CMS\Uri\Uri::current() . '?eervol_reset=1';
    $html     = '';

    if ($urlBeperkt) {
        $html .= '<a href="' . htmlspecialchars($urlBeperkt, ENT_QUOTES) . '"
                     class="uk-button uk-button-default" target="_blank" rel="noopener">
                     <span uk-icon="file-pdf"></span>&nbsp; Beperkte editie bekijken
                  </a>';
    }

    $html .= '<p class="uk-text-small uk-text-muted uk-margin-small-top">
                  <a href="' . htmlspecialchars($resetUrl, ENT_QUOTES) . '">
                      <span uk-icon="refresh"></span>&nbsp; Toch een inlogcode? Klik hier.
                  </a>
              </p>';

    return $html;
}

// -----------------------------------------------------------------------
// Recente editie — eerste bezoek: popup automatisch openen
// -----------------------------------------------------------------------
$modalId = 'eervol-slot-' . (int) $item->id;

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
                       autocomplete="off">
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
        UIkit.modal(document.getElementById("' . $modalId . '")).show();
    });
</script>';
