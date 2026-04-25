<?php
namespace KveoNl\Plugin\System\Eervolslot\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

/**
 * Verwerkt het ledenwachtwoord voor EERVOL vóórdat de pagina wordt opgebouwd.
 *
 * Doordat dit in onAfterInitialise gebeurt zijn er nog geen headers verstuurd,
 * zodat de redirect na het verwerken van het formulier probleemloos werkt.
 */
final class Eervolslot extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onAfterInitialise' => 'controleerWachtwoord'];
    }

    public function controleerWachtwoord(Event $event): void
    {
        $app = $this->getApplication();

        // Alleen voor front-end bezoekers
        if (!$app->isClient('site')) {
            return;
        }

        $input = $app->input;

        // Alleen reageren op een POST met het EERVOL-wachtwoordveld
        if ($input->getMethod() !== 'POST' || !$input->post->getString('eervol_pw')) {
            return;
        }

        $ingevoerd = $input->post->getString('eervol_pw', '');
        $correct   = $this->params->get('wachtwoord', '');

        if (!empty($correct) && $ingevoerd === $correct) {
            $app->getSession()->set('eervol_toegang', true);
        }

        // Redirect naar dezelfde pagina (GET) zodat vernieuwen het formulier niet opnieuw verstuurt
        $terug = $input->server->getString('HTTP_REFERER', \Joomla\CMS\Uri\Uri::current());
        $app->redirect($terug);
    }
}
