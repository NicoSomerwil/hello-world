<?php
namespace KveoNl\Plugin\Content\Kveocode\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

/**
 * Automatiseert twee dingen voor EERVOL-artikelen:
 *   1. Vult het custom field 'code' eenmalig bij aanmaken van een artikel.
 *   2. Herberekent 'is-openbaar' voor alle EERVOL-edities na elke opslag,
 *      zodat edities ouder dan de twee meest recente automatisch openbaar worden.
 *
 * De 'code' wordt ook als bestandsnaam-basis gebruikt voor de PDF-bestanden.
 * Gebruik generate_code.php (zelfde SECRET_KEY) om de naam van de PDF-bestanden
 * vooraf te berekenen vóórdat je ze uploadt.
 */
final class Kveocode extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    // Moet overeenkomen met de SECRET_KEY in generate_code.php.
    // Vervang dit door een lange willekeurige string en houd hem geheim.
    private const SECRET_KEY = 'v3Ry$3cr3t!K3y-Ch4ng3-M3-1n-Pr0d';

    private const ALPHABET    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    private const CODE_LENGTH = 10;

    // Alias van de EERVOL-categorie in Joomla (Componenten → Artikelen → Categorieën)
    private const CATEGORY_ALIAS = 'eervol';

    // Namen van de custom fields die deze plugin beheert
    private const FIELD_CODE        = 'code';
    private const FIELD_EDITIE_NR   = 'editie-nummer';
    private const FIELD_IS_OPENBAAR = 'is-openbaar';

    // Aantal recente edities dat achter het slotje blijft
    private const BESCHERMD = 2;

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

        // Stap 1: genereer de code als die nog niet bestaat
        if (isset($veldIds[self::FIELD_CODE])) {
            $this->vulCodeIn((int) $article->id, $veldIds[self::FIELD_CODE], $article->title);
        }

        // Stap 2: herbereken is-openbaar voor alle EERVOL-edities
        if (isset($veldIds[self::FIELD_EDITIE_NR], $veldIds[self::FIELD_IS_OPENBAAR])) {
            $this->herbereken($veldIds[self::FIELD_EDITIE_NR], $veldIds[self::FIELD_IS_OPENBAAR]);
        }
    }

    // --- Stap 1: code genereren ---

    private function vulCodeIn(int $artikelId, int $veldId, string $titel): void
    {
        if ($this->leesVeldWaarde($artikelId, $veldId) !== '') {
            return; // overschrijf nooit een bestaande code
        }

        $this->schrijfVeldWaarde($artikelId, $veldId, $this->genereerCode($titel));
    }

    private function genereerCode(string $titel): string
    {
        $hash = hash_hmac('sha256', $titel, self::SECRET_KEY, binary: true);
        $code = '';
        $len  = strlen(self::ALPHABET);

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[ord($hash[$i]) % $len];
        }

        return $code;
    }

    // --- Stap 2: is-openbaar herberekenen ---

    private function herbereken(int $editieNrVeldId, int $isOpenbaarVeldId): void
    {
        $db = $this->getDatabase();

        // Haal alle EERVOL-artikelen op met hun editienummer
        $query = $db->getQuery(true)
            ->select([$db->quoteName('a.id'), $db->quoteName('fv.value', 'editie_nr')])
            ->from($db->quoteName('#__content', 'a'))
            ->join(
                'INNER',
                $db->quoteName('#__categories', 'c')
                    . ' ON c.id = a.catid AND c.alias = ' . $db->quote(self::CATEGORY_ALIAS)
            )
            ->join(
                'LEFT',
                $db->quoteName('#__fields_values', 'fv')
                    . ' ON fv.item_id = a.id AND fv.field_id = ' . $editieNrVeldId
            );

        $artikelen = $db->setQuery($query)->loadObjectList();

        if (empty($artikelen)) {
            return;
        }

        $editienummers = array_map('intval', array_column($artikelen, 'editie_nr'));
        $maxEditie     = max($editienummers ?: [0]);

        foreach ($artikelen as $artikel) {
            $nr          = (int) $artikel->editie_nr;
            // Openbaar als het editienummer bekend is én niet bij de meest recente BESCHERMD hoort
            $isOpenbaar  = ($nr > 0 && $nr <= $maxEditie - self::BESCHERMD) ? '1' : '0';

            $this->schrijfVeldWaarde((int) $artikel->id, $isOpenbaarVeldId, $isOpenbaar);
        }
    }

    // --- Database helpers ---

    private function isEervolCategorie(int $catid): bool
    {
        $db    = $this->getDatabase();
        $catid = (int) $catid;
        $query = $db->getQuery(true)
            ->select($db->quoteName('alias'))
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('id') . ' = ' . $catid);

        return $db->setQuery($query)->loadResult() === self::CATEGORY_ALIAS;
    }

    /** @return array<string, int> veldnaam => veld-ID */
    private function getVeldIds(): array
    {
        $db    = $this->getDatabase();
        $namen = [self::FIELD_CODE, self::FIELD_EDITIE_NR, self::FIELD_IS_OPENBAAR];

        $quoted = implode(',', array_map([$db, 'quote'], $namen));

        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('name')])
            ->from($db->quoteName('#__fields'))
            ->where($db->quoteName('name') . ' IN (' . $quoted . ')')
            ->where($db->quoteName('context') . ' = ' . $db->quote('com_content.article'))
            ->where($db->quoteName('state') . ' = 1');

        $rijen = $db->setQuery($query)->loadObjectList('name');

        $map = [];
        foreach ($rijen as $naam => $rij) {
            $map[$naam] = (int) $rij->id;
        }

        return $map;
    }

    private function leesVeldWaarde(int $artikelId, int $veldId): string
    {
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
}
