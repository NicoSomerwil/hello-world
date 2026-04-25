<?php
namespace KveoNl\Plugin\Content\Kveocode\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

/**
 * Hernoemt en verplaatst EERVOL-PDF's automatisch bij het opslaan van een artikel.
 *
 * Workflow voor de beheerder:
 *   1. Upload de twee PDF's met een willekeurige naam naar /images/eervol/
 *   2. Selecteer ze in de velden 'Leden' en 'NIET-leden (beperkt)'
 *   3. Sla het artikel op
 *   → Volledig PDF: /images/eervol/volledig/E{nr}_{code}.pdf
 *   → Beperkt  PDF: /images/eervol/beperkt/E{nr}_{code}.pdf
 *   → Beperkt map:  automatisch opgeschoond, alleen de 3 recentste bewaard
 */
final class Kveocode extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    // Moet gelijk zijn aan de SECRET_KEY in de acfphp 'code' veld en in generate_code.php
    private const SECRET_KEY   = 'v3Ry$3cr3t!K3y-Ch4ng3-M3-1n-Pr0d';
    private const ALPHABET     = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    private const CODE_LENGTH  = 10;

    private const CATEGORY_ALIAS = 'eervol';

    // Namen van de relevante custom fields
    private const FIELD_NUMMER  = 'nummer';
    private const FIELD_LEDEN   = 'leden';
    private const FIELD_BEPERKT = 'niet-leden-beperkt';

    // Submappen onder /images/eervol/ (relatief aan JPATH_ROOT)
    private const MAP_VOLLEDIG = 'images/eervol/volledig';
    private const MAP_BEPERKT  = 'images/eervol/beperkt';

    // Aantal beperkte PDF's bewaren; oudere worden automatisch verwijderd
    private const BEWAAR_BEPERKT = 3;

    public static function getSubscribedEvents(): array
    {
        return ['onContentAfterSave' => 'handleArticleSave'];
    }

    public function handleArticleSave(Event $event): void
    {
        $context = $event->getArgument('context', '');
        $article = $event->getArgument('subject');

        if ($context !== 'com_content.article' || $article === null) {
            return;
        }

        if (!$this->isEervolCategorie((int) $article->catid)) {
            return;
        }

        $veldIds = $this->getVeldIds();
        if (empty($veldIds)) {
            return;
        }

        $this->verwerkPdfBestanden((int) $article->id, $article->title, $veldIds);
        $this->ruimBeperktMapOp();
    }

    // -------------------------------------------------------------------------
    // PDF verwerking
    // -------------------------------------------------------------------------

    private function verwerkPdfBestanden(int $artikelId, string $titel, array $veldIds): void
    {
        if (!isset($veldIds[self::FIELD_NUMMER], $veldIds[self::FIELD_LEDEN], $veldIds[self::FIELD_BEPERKT])) {
            return;
        }

        $nummer = (int) $this->leesWaarde($artikelId, $veldIds[self::FIELD_NUMMER]);
        if ($nummer === 0 || empty(trim($titel))) {
            return;
        }

        $this->maakMapAan(self::MAP_VOLLEDIG);
        $this->maakMapAan(self::MAP_BEPERKT);

        $prefix = 'E' . $nummer . '_';

        // --- Volledige PDF (leden) ---
        $huidig   = $this->leesWaarde($artikelId, $veldIds[self::FIELD_LEDEN]);
        $verwacht = self::MAP_VOLLEDIG . '/' . $prefix . $this->genereerCode($titel . ':volledig') . '.pdf';

        if ($huidig && $huidig !== $verwacht) {
            if ($this->verplaats($huidig, $verwacht)) {
                $this->schrijfVeldWaarde($artikelId, $veldIds[self::FIELD_LEDEN], $verwacht);
            }
        }

        // --- Beperkte PDF (niet-leden) ---
        $huidig   = $this->leesWaarde($artikelId, $veldIds[self::FIELD_BEPERKT]);
        $verwacht = self::MAP_BEPERKT . '/' . $prefix . $this->genereerCode($titel . ':beperkt') . '.pdf';

        if ($huidig && $huidig !== $verwacht) {
            if ($this->verplaats($huidig, $verwacht)) {
                $this->schrijfVeldWaarde($artikelId, $veldIds[self::FIELD_BEPERKT], $verwacht);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Opschonen beperkt-map
    // -------------------------------------------------------------------------

    private function ruimBeperktMapOp(): void
    {
        $map = JPATH_ROOT . '/' . self::MAP_BEPERKT;
        if (!is_dir($map)) {
            return;
        }

        $bestanden = glob($map . '/E*.pdf') ?: [];
        if (count($bestanden) <= self::BEWAAR_BEPERKT) {
            return;
        }

        // Sorteer oplopend op editienummer (uit bestandsnaam: E{nummer}_...)
        usort($bestanden, static function (string $a, string $b): int {
            preg_match('/E(\d+)_/', basename($a), $mA);
            preg_match('/E(\d+)_/', basename($b), $mB);
            return (int) ($mA[1] ?? 0) <=> (int) ($mB[1] ?? 0);
        });

        // Verwijder de oudste; bewaar alleen de BEWAAR_BEPERKT recentste
        $teVerwijderen = array_slice($bestanden, 0, count($bestanden) - self::BEWAAR_BEPERKT);
        foreach ($teVerwijderen as $bestand) {
            @unlink($bestand);
        }
    }

    // -------------------------------------------------------------------------
    // Bestandssysteem helpers
    // -------------------------------------------------------------------------

    private function maakMapAan(string $relatief): void
    {
        $abs = JPATH_ROOT . '/' . $relatief;
        if (!is_dir($abs)) {
            mkdir($abs, 0755, true);
        }
    }

    private function verplaats(string $van, string $naar): bool
    {
        $absVan  = JPATH_ROOT . '/' . ltrim($van, '/');
        $absNaar = JPATH_ROOT . '/' . ltrim($naar, '/');

        if (!file_exists($absVan)) {
            return false;
        }

        return rename($absVan, $absNaar);
    }

    // -------------------------------------------------------------------------
    // Database helpers
    // -------------------------------------------------------------------------

    /**
     * Leest een veldwaarde uit de DB; als die nog leeg is (nieuw artikel, eerste opslag)
     * valt het terug op de POST-data zodat het ook bij de eerste keer opslaan werkt.
     */
    private function leesWaarde(int $artikelId, int $veldId): string
    {
        $opgeslagen = $this->leesVeldWaarde($artikelId, $veldId);
        if ($opgeslagen !== '') {
            return $opgeslagen;
        }

        // Fallback: POST-data (Joomla stuurt veldwaarden mee als jcfields[{id}])
        $jcfields = Factory::getApplication()->input->post->get('jcfields', [], 'ARRAY');
        return (string) ($jcfields[$veldId] ?? '');
    }

    private function leesVeldWaarde(int $artikelId, int $veldId): string
    {
        if ($veldId === 0) {
            return '';
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('value'))
            ->from($db->quoteName('#__fields_values'))
            ->where($db->quoteName('field_id') . ' = ' . $veldId)
            ->where($db->quoteName('item_id') . ' = ' . $artikelId);

        return (string) ($db->setQuery($query)->loadResult() ?? '');
    }

    private function schrijfVeldWaarde(int $artikelId, int $veldId, string $waarde): void
    {
        $db = $this->getDatabase();

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__fields_values'))
                ->where($db->quoteName('field_id') . ' = ' . $veldId)
                ->where($db->quoteName('item_id') . ' = ' . $artikelId)
        )->execute();

        $db->insertObject('#__fields_values', (object) [
            'field_id' => $veldId,
            'item_id'  => $artikelId,
            'value'    => $waarde,
        ]);
    }

    /** @return array<string, int> veldnaam => veld-ID */
    private function getVeldIds(): array
    {
        $db     = $this->getDatabase();
        $namen  = [self::FIELD_NUMMER, self::FIELD_LEDEN, self::FIELD_BEPERKT];
        $quoted = implode(',', array_map([$db, 'quote'], $namen));

        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('name')])
            ->from($db->quoteName('#__fields'))
            ->where($db->quoteName('name') . ' IN (' . $quoted . ')')
            ->where($db->quoteName('context') . ' = ' . $db->quote('com_content.article'))
            ->where($db->quoteName('state') . ' = 1');

        $rijen = $db->setQuery($query)->loadObjectList('name');
        $map   = [];
        foreach ($rijen as $naam => $rij) {
            $map[$naam] = (int) $rij->id;
        }

        return $map;
    }

    private function isEervolCategorie(int $catid): bool
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('alias'))
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('id') . ' = ' . $catid);

        return $db->setQuery($query)->loadResult() === self::CATEGORY_ALIAS;
    }

    private function genereerCode(string $invoer): string
    {
        $hash = hash_hmac('sha256', $invoer, self::SECRET_KEY, binary: true);
        $code = '';
        $len  = strlen(self::ALPHABET);

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[ord($hash[$i]) % $len];
        }

        return $code;
    }
}
