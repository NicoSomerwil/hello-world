<?php
namespace KveoNl\Plugin\System\Eervolslot\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

/**
 * Verwerkt de inlogcode voor EERVOL vóórdat de pagina wordt opgebouwd.
 *
 * Sessievariabelen die deze plugin beheert:
 *   eervol_toegang  (bool)   – juiste code ingevoerd, volledige PDF tonen
 *   eervol_besloten (string) – 'beperkt' als de bezoeker leeg of fout invulde
 *
 * Een GET-parameter ?eervol_reset=1 wist beide variabelen zodat de prompt opnieuw verschijnt.
 */
final class Eervolslot extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onAfterInitialise' => 'controleerToegang'];
    }

    public function controleerToegang(Event $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $input   = $app->input;
        $session = $app->getSession();

        // --- GET: sessie wissen via "Toch een code?" link ---
        if ($input->getMethod() === 'GET' && $input->get->getInt('eervol_reset', 0) === 1) {
            $session->clear('eervol_toegang');
            $session->clear('eervol_besloten');
            // Redirect naar dezelfde pagina zónder de query-parameter
            $app->redirect(Uri::current());
            return;
        }

        // --- POST: formulier uit de popup verwerken ---
        if ($input->getMethod() !== 'POST') {
            return;
        }

        // Alleen reageren als ons eigen verborgen veld aanwezig is
        if (!$input->post->getBool('eervol_form_submitted', false)) {
            return;
        }

        $ingevoerd = $input->post->getString('eervol_pw', '');
        $correct   = $this->params->get('wachtwoord', '');

        if (!empty($correct) && $ingevoerd === $correct) {
            // Juiste code → volledige PDF
            $session->set('eervol_toegang', true);
            $session->clear('eervol_besloten');
        } else {
            // Leeg of fout → beperkte PDF
            $session->set('eervol_besloten', 'beperkt');
            $session->clear('eervol_toegang');
        }

        // Redirect (GET) zodat vernieuwen het formulier niet opnieuw verstuurt
        $terug = $input->server->getString('HTTP_REFERER', Uri::current());
        $app->redirect($terug);
    }
}
