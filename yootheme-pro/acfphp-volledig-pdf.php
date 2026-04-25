<?php
/**
 * Yootheme Pro — acfphp element: "Volledige editie (slot)"
 *
 * Plak deze code in een acfphp custom field element in je EERVOL artikeltemplate.
 * Zet dit element ONDER de knop voor de beperkte/vrije PDF.
 *
 * Wat het doet:
 *   - Historische edities (is-openbaar = 1): PDF-knop direct tonen, geen slot.
 *   - Recente edities die al ontgrendeld zijn (sessie): PDF-knop tonen.
 *   - Recente edities zonder sessie: wachtwoordformulier tonen.
 */

$app        = \Joomla\CMS\Factory::getApplication();
$session    = $app->getSession();

$isOpenbaar = ($item->jcfields['is-openbaar']->rawvalue ?? '0') === '1';
$toegang    = $session->get('eervol_toegang', false);

// Pad naar de volledige PDF (document-veld 'leden')
// Joomla slaat het pad op relatief aan de site-root, bijv. "images/eervol/xxx-volledig.pdf"
$pad    = $item->jcfields['leden']->rawvalue ?? '';
$pdfUrl = $pad ? (\Joomla\CMS\Uri\Uri::root() . $pad) : '';

if (empty($pdfUrl)) {
    return ''; // geen PDF ingesteld, niets tonen
}

// --- Toegang verleend (openbaar of ontgrendeld) ---
if ($isOpenbaar || $toegang) {
    return '
    <a href="' . htmlspecialchars($pdfUrl, ENT_QUOTES) . '"
       class="uk-button uk-button-primary" target="_blank" rel="noopener">
        <span uk-icon="file-pdf"></span>&nbsp; Volledige editie bekijken
    </a>';
}

// --- Slot: wachtwoordformulier ---
return '
<div class="uk-card uk-card-default uk-card-body" style="max-width:420px">
    <h4 class="uk-card-title">
        <span uk-icon="lock"></span>&nbsp; Exclusief voor leden
    </h4>
    <p class="uk-text-small uk-text-muted">
        Voer het ledenwachtwoord in om de volledige editie te bekijken.
        Na één keer invoeren bent u voor dit bezoek ontgrendeld — ook voor andere edities.
    </p>
    <form method="post" action="">
        <div class="uk-margin-small">
            <input class="uk-input"
                   type="password"
                   name="eervol_pw"
                   placeholder="Ledenwachtwoord"
                   autocomplete="current-password"
                   required>
        </div>
        <button class="uk-button uk-button-primary" type="submit">
            <span uk-icon="unlock"></span>&nbsp; Toegang
        </button>
    </form>
</div>';
