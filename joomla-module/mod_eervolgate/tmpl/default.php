<?php
/**
 * mod_eervolgate — standaard template
 *
 * Variabelen beschikbaar vanuit mod_eervolgate.php:
 *   $isOpenbaar    bool    true als editie ouder is dan X maanden
 *   $heeftToegang  bool    true als juiste inlogcode ingevoerd deze sessie
 *   $besloten      string  'beperkt' als leeg/fout ingevuld
 *   $urlVolledig   string  volledige URL naar volledige PDF (kan leeg zijn)
 *   $urlBeperkt    string  volledige URL naar beperkte PDF (kan leeg zijn)
 *   $modalId       string  uniek HTML-id voor de modal
 */

defined('_JEXEC') or die;

use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

// -----------------------------------------------------------------------
// Historische editie (ouder dan X maanden) → volledige PDF altijd vrij
// -----------------------------------------------------------------------
if ($isOpenbaar) :
    if (empty($urlVolledig)) return;
?>
<a href="<?= htmlspecialchars($urlVolledig, ENT_QUOTES) ?>"
   class="uk-button uk-button-primary" target="_blank" rel="noopener">
    <span uk-icon="file-pdf"></span>&nbsp; Volledige editie bekijken
</a>
<?php
    return;
endif;

// -----------------------------------------------------------------------
// Recente editie — juiste inlogcode al ingevoerd deze sessie
// -----------------------------------------------------------------------
if ($heeftToegang) :
    if (empty($urlVolledig)) return;
?>
<a href="<?= htmlspecialchars($urlVolledig, ENT_QUOTES) ?>"
   class="uk-button uk-button-primary" target="_blank" rel="noopener">
    <span uk-icon="file-pdf"></span>&nbsp; Volledige editie bekijken
</a>
<?php
    return;
endif;

// -----------------------------------------------------------------------
// Recente editie — besloten voor beperkt: beperkte PDF + retry-link
// -----------------------------------------------------------------------
if ($besloten === 'beperkt') :
    $resetUri = clone Uri::getInstance();
    $resetUri->setVar('eervol_reset', '1');
    $resetUrl = htmlspecialchars($resetUri->toString(), ENT_QUOTES);
?>
<?php if ($urlBeperkt) : ?>
<p class="uk-text-small uk-text-muted uk-margin-remove-bottom">
    Dit is de beperkte versie van deze editie.
</p>
<a href="<?= htmlspecialchars($urlBeperkt, ENT_QUOTES) ?>"
   class="uk-button uk-button-default" target="_blank" rel="noopener">
    <span uk-icon="file-pdf"></span>&nbsp; Beperkte editie bekijken
</a>
<?php endif; ?>
<p class="uk-text-small uk-text-muted uk-margin-small-top">
    <a href="<?= $resetUrl ?>">
        <span uk-icon="refresh"></span>&nbsp; Toch een inlogcode? Klik hier.
    </a>
</p>
<?php
    return;
endif;

// -----------------------------------------------------------------------
// Eerste bezoek — popup automatisch openen
// -----------------------------------------------------------------------
?>
<div id="<?= htmlspecialchars($modalId, ENT_QUOTES) ?>" uk-modal="bg-close: false; esc-close: false">
    <div class="uk-modal-dialog uk-modal-body">

        <h3 class="uk-modal-title">EERVOL &mdash; Volledige editie</h3>

        <p>
            Vul hier de inlogcode in om de volledige editie te bekijken.<br>
            <span class="uk-text-small uk-text-muted">
                Heb je geen inlogcode? Laat het veld leeg en klik op <strong>OK</strong>.
                Je ziet dan de beperkte versie.
            </span>
        </p>

        <form method="post" action="">
            <input type="hidden" name="<?= Session::getFormToken() ?>" value="1">
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
        UIkit.modal(document.getElementById(<?= json_encode($modalId) ?>)).show();
    });
</script>
