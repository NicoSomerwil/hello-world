<?php
namespace KveoNl\Plugin\Content\Kveocode\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

final class Kveocode extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    private const SECRET_KEY   = 'v3Ry$3cr3t!K3y-Ch4ng3-M3-1n-Pr0d';
    private const ALPHABET     = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    private const CODE_LENGTH  = 10;

    private const CATEGORY_ALIAS = 'eervol';
    private const FIELD_NUMMER   = 'nummer';
    private const FIELD_LEDEN    = 'leden';
    private const FIELD_BEPERKT  = 'niet-leden-beperkt';
    private const MAP_VOLLEDIG   = 'images/eervol/volledig';
    private const MAP_BEPERKT    = 'images/eervol/beperkt';
    private const BEWAAR_BEPERKT = 3;

    // Zet op false als alles werkt om het logbestand niet langer te vullen
    private const DEBUG = true;
    private const LOG_BESTAND = 'images/eervol/kveocode-debug.log';

    public static function getSubscribedEvents(): array
    {
        // Prioriteit -100: loopt na de Joomla velden-plugin zodat veldwaarden
        // al in de database staan als wij ze opvragen.
        return ['onContentAfterSave' => ['handleArticleSave', -100]];
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

        $this->debug('=== Artikel opgeslagen ===', [
            'id'      => $article->id,
            'titel'   => $article->title,
            'veldIds' => $veldIds,
        ]);

        if (empty($veldIds)) {
            $this->debug('FOUT: geen veld-IDs gevonden — controleer veldnamen in Joomla');
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
            $this->debug('FOUT: een of meer veld-IDs ontbreken', $veldIds);
            return;
        }

        $nummerRauw = $this->leesWaarde($artikelId, $veldIds[self::FIELD_NUMMER]);
        $nummer     = (int) $nummerRauw;

        $this->debug('Nummer veld', ['rauw' => $nummerRauw, 'als_int' => $nummer]);

        if ($nummer === 0 || empty(trim($titel))) {
            $this->debug('FOUT: nummer is 0 of titel is leeg — artikel goed ingevuld?');
            return;
        }

        $this->maakMapAan(self::MAP_VOLLEDIG);
        $this->maakMapAan(self::MAP_BEPERKT);

        $prefix = 'E' . $nummer . '_';

        // --- Volledige PDF ---
        // Het document-veld slaat JSON op: {"file":"images/...","linktext":"..."}
        // We extraheren het pad, verplaatsen het bestand en schrijven de bijgewerkte JSON terug.
        $rawLeden      = $this->leesRawVeld($artikelId, $veldIds[self::FIELD_LEDEN]);
        $huidigLeden   = $this->parseerPad($rawLeden);
        $verwachtLeden = self::MAP_VOLLEDIG . '/' . $prefix . $this->genereerCode($titel . ':volledig') . '.pdf';

        $this->debug('Volledig PDF', [
            'raw'            => $rawLeden,
            'huidig_pad'     => $huidigLeden,
            'verwacht'       => $verwachtLeden,
            'bestand_bestaat'=> $huidigLeden && file_exists(JPATH_ROOT . '/' . ltrim($huidigLeden, '/')) ? 'JA' : 'NEE',
        ]);

        if ($huidigLeden && $huidigLeden !== $verwachtLeden) {
            if ($this->verplaats($huidigLeden, $verwachtLeden)) {
                $this->schrijfVeldWaarde($artikelId, $veldIds[self::FIELD_LEDEN], $this->bijwerkenPad($rawLeden, $verwachtLeden));
                $this->debug('Volledig PDF verplaatst: OK');
            } else {
                $this->debug('Volledig PDF verplaatsen MISLUKT');
            }
        }

        // --- Beperkte PDF ---
        $rawBeperkt      = $this->leesRawVeld($artikelId, $veldIds[self::FIELD_BEPERKT]);
        $huidigBeperkt   = $this->parseerPad($rawBeperkt);
        $verwachtBeperkt = self::MAP_BEPERKT . '/' . $prefix . $this->genereerCode($titel . ':beperkt') . '.pdf';

        $this->debug('Beperkt PDF', [
            'raw'            => $rawBeperkt,
            'huidig_pad'     => $huidigBeperkt,
            'verwacht'       => $verwachtBeperkt,
            'bestand_bestaat'=> $huidigBeperkt && file_exists(JPATH_ROOT . '/' . ltrim($huidigBeperkt, '/')) ? 'JA' : 'NEE',
        ]);

        if ($huidigBeperkt && $huidigBeperkt !== $verwachtBeperkt) {
            if ($this->verplaats($huidigBeperkt, $verwachtBeperkt)) {
                $this->schrijfVeldWaarde($artikelId, $veldIds[self::FIELD_BEPERKT], $this->bijwerkenPad($rawBeperkt, $verwachtBeperkt));
                $this->debug('Beperkt PDF verplaatst: OK');
            } else {
                $this->debug('Beperkt PDF verplaatsen MISLUKT');
            }
        }
    }

    /**
     * Extraheert het bestandspad uit een veldwaarde die JSON kan zijn
     * ({"file":"images/...","linktext":"..."}) of een gewone string.
     */
    private function parseerPad(string $waarde): string
    {
        if (empty($waarde)) {
            return '';
        }

        $decoded = json_decode($waarde, true);
        if (is_array($decoded)) {
            return (string) ($decoded['file'] ?? $decoded['src'] ?? $decoded['value'] ?? $decoded['path'] ?? '');
        }

        return $waarde;
    }

    /**
     * Vervangt het bestandspad in de originele veldwaarde (JSON of string)
     * zodat de volledige veldstructuur (inclusief linktext) bewaard blijft.
     */
    private function bijwerkenPad(string $origineel, string $nieuwPad): string
    {
        $decoded = json_decode($origineel, true);
        if (is_array($decoded)) {
            $decoded['file'] = $nieuwPad;
            return json_encode($decoded, JSON_UNESCAPED_SLASHES);
        }

        return $nieuwPad;
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

        usort($bestanden, static function (string $a, string $b): int {
            preg_match('/E(\d+)_/', basename($a), $mA);
            preg_match('/E(\d+)_/', basename($b), $mB);
            return (int) ($mA[1] ?? 0) <=> (int) ($mB[1] ?? 0);
        });

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
            $this->debug("verplaats() MISLUKT: bronbestand bestaat niet: {$absVan}");
            return false;
        }

        $resultaat = rename($absVan, $absNaar);
        if (!$resultaat) {
            $this->debug("rename() MISLUKT: {$absVan} → {$absNaar}");
        }

        return $resultaat;
    }

    // -------------------------------------------------------------------------
    // Database helpers
    // -------------------------------------------------------------------------

    /**
     * Leest de ruwe veldwaarde uit de DB (inclusief JSON-structuur).
     * Gebruik dit voor document-velden waarbij de JSON intact moet blijven.
     * Valt terug op POST-data bij eerste opslag van een nieuw artikel.
     */
    private function leesRawVeld(int $artikelId, int $veldId): string
    {
        $waarde = $this->leesVeldWaarde($artikelId, $veldId);

        if ($waarde === '') {
            $jcfields = Factory::getApplication()->input->post->get('jcfields', [], 'ARRAY');
            $rauw     = $jcfields[$veldId] ?? '';
            $waarde   = is_array($rauw) ? json_encode($rauw) : (string) $rauw;
        }

        return $waarde;
    }

    /**
     * Leest een simpele veldwaarde (integer, tekst) — zonder JSON-parsing.
     * Gebruik dit voor velden als 'nummer'.
     */
    private function leesWaarde(int $artikelId, int $veldId): string
    {
        $waarde = $this->leesVeldWaarde($artikelId, $veldId);

        if ($waarde === '') {
            $jcfields = Factory::getApplication()->input->post->get('jcfields', [], 'ARRAY');
            $rauw     = $jcfields[$veldId] ?? '';
            $waarde   = is_array($rauw) ? (string) ($rauw[0] ?? '') : (string) $rauw;
        }

        return $waarde;
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

        $rij           = new \stdClass();
        $rij->field_id = $veldId;
        $rij->item_id  = $artikelId;
        $rij->value    = $waarde;
        $db->insertObject('#__fields_values', $rij);
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

    // -------------------------------------------------------------------------
    // Debug logging
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $data */
    private function debug(string $bericht, array $data = []): void
    {
        if (!self::DEBUG) {
            return;
        }

        $regels = [date('[Y-m-d H:i:s]') . ' ' . $bericht];
        foreach ($data as $sleutel => $waarde) {
            $regels[] = '  ' . $sleutel . ': ' . (is_array($waarde) ? json_encode($waarde) : $waarde);
        }

        file_put_contents(
            JPATH_ROOT . '/' . self::LOG_BESTAND,
            implode(PHP_EOL, $regels) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
