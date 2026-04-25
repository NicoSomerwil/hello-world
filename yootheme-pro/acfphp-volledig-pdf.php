<?php
/**
 * Yootheme Pro — acfphp element: "Volledige editie (slot)"
 *
 * Plak deze code in een acfphp custom field element in de EERVOL sublayout/artikeltemplate.
 * Zet dit element NAAST of ONDER de knop voor de beperkte PDF (apart element, altijd zichtbaar).
 *
 * Gedrag:
 *   - Historische editie (is-openbaar = 1) → PDF-knop direct zichtbaar, geen slot
 *   - Recente editie, sessie al ontgrendeld → PDF-knop direct zichtbaar
 *   - Recente editie, nog niet ontgrendeld  → slot-knop die een UIkit popup opent
 *       • Juist wachtwoord → pagina herlaadt, PDF-knop zichtbaar
 *       • Fout wachtwoord  → popup sluit, pagina herlaadt, slot-knop terug zichtbaar
 *
 * Het wachtwoord geldt voor het hele bezoek (browser-sessie).
 * Eén keer invoeren ontgrendelt alle recente edities tegelijk.
 */

$app        = \Joomla\CMS\Factory::getApplication();
$session    = $app->getSession();

$isOpenbaar = ($item->jcfields['is-openbaar']->rawvalue ?? '0') === '1';
$toegang    = $session->get('eervol_toegang', false);

// Pad naar de volledige PDF (document-veld 'leden')
// Joomla slaat het relatieve pad op, bijv. "images/eervol/p1qDjEgRAZ-volledig.pdf"
$pad    = $item->jcfields['leden']->rawvalue ?? '';
$pdfUrl = $pad ? (\Joomla\CMS\Uri\Uri::root() . $pad) : '';

if (empty($pdfUrl)) {
    return ''; // veld 'leden' nog niet ingevuld voor dit artikel
}

// --- Toegang verleend: historische editie of sessie al ontgrendeld ---
if ($isOpenbaar || $toegang) {
    return '
    <a href="' . htmlspecialchars($pdfUrl, ENT_QUOTES) . '"
       class="uk-button uk-button-primary" target="_blank" rel="noopener">
        <span uk-icon="file-pdf"></span>&nbsp; Volledige editie bekijken
    </a>';
}

// --- Slot: knop opent een UIkit modal met het wachtwoordformulier ---
// Uniek modal-ID per artikel voorkomt conflicten als meerdere edities op één pagina staan.
$modalId = 'eervol-slot-' . (int) $item->id;

return '
<div>
    <!-- Slot-knop -->
    <a class="uk-button uk-button-secondary" href="#' . $modalId . '" uk-toggle>
        <span uk-icon="lock"></span>&nbsp; Volledige editie (leden)
    </a>

    <!-- Popup / modal -->
    <div id="' . $modalId . '" uk-modal>
        <div class="uk-modal-dialog uk-modal-body">
            <button class="uk-modal-close-default" type="button" uk-close></button>

            <h3 class="uk-modal-title">
                <span uk-icon="lock"></span>&nbsp; Exclusief voor leden
            </h3>
            <p class="uk-text-muted">
                Voer het ledenwachtwoord in om de volledige editie te bekijken.<br>
                <small>Na één keer invoeren bent u voor dit bezoek ontgrendeld — ook voor andere edities.</small>
            </p>

            <form method="post" action="">
                <div class="uk-margin">
                    <input class="uk-input"
                           type="password"
                           name="eervol_pw"
                           placeholder="Ledenwachtwoord"
                           autocomplete="current-password"
                           required>
                </div>
                <div class="uk-flex uk-flex-between">
                    <button class="uk-button uk-button-primary" type="submit">
                        <span uk-icon="unlock"></span>&nbsp; Toegang
                    </button>
                    <button class="uk-button uk-button-default uk-modal-close" type="button">
                        Annuleren
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>';
