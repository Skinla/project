<?php
/**
 * Create cloud CRM contact on box when missing from contact_mapping.json (file lock).
 * Call only after prolog_before + crm module.
 */
class EnsureCloudContact
{
    /**
     * If cloud contact is not in mapping, create on box and persist mapping.
     *
     * @param string $mappingPath full path to contact_mapping.json
     * @param int $cloudContactId
     * @param array $contact crm.contact.get result from cloud
     * @param array $userMapping decoded user_mapping.json (expects ['users' => ...])
     * @return int|null box contact ID or null on failure
     */
    public static function ensureMapped(string $mappingPath, int $cloudContactId, array $contact, array $userMapping): ?int
    {
        if ($cloudContactId <= 0) {
            return null;
        }

        $fh = fopen($mappingPath, 'c+');
        if (!$fh) {
            return null;
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return null;
        }

        try {
            rewind($fh);
            $raw = stream_get_contents($fh);
            $data = $raw !== false && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($data)) {
                $data = [];
            }
            if (!isset($data['contacts']) || !is_array($data['contacts'])) {
                $data['contacts'] = [];
            }

            $key = (string)$cloudContactId;
            if (isset($data['contacts'][$key])) {
                return (int)$data['contacts'][$key];
            }

            $boxId = self::createOnBox($contact, $userMapping);
            if (!$boxId) {
                return null;
            }

            $data['contacts'][$key] = $boxId;
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, $json);
            fflush($fh);

            return $boxId;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @return int|null new box contact ID
     */
    private static function createOnBox(array $contact, array $userMapping): ?int
    {
        global $DB;

        $users = $userMapping['users'] ?? [];
        $assignedBy = (int)($users[(string)($contact['ASSIGNED_BY_ID'] ?? '')] ?? 1);

        $item = [
            'NAME' => $contact['NAME'] ?? '',
            'LAST_NAME' => $contact['LAST_NAME'] ?? '',
            'SECOND_NAME' => $contact['SECOND_NAME'] ?? '',
            'POST' => $contact['POST'] ?? '',
            'COMMENTS' => $contact['COMMENTS'] ?? '',
            'ASSIGNED_BY_ID' => $assignedBy,
        ];

        if (!empty($contact['CREATED_BY_ID'])) {
            $item['CREATED_BY_ID'] = (int)($users[(string)$contact['CREATED_BY_ID']] ?? 1);
        }
        if (!empty($contact['MODIFY_BY_ID'])) {
            $item['MODIFY_BY_ID'] = (int)($users[(string)$contact['MODIFY_BY_ID']] ?? 1);
        }

        $fm = [];
        if (!empty($contact['PHONE']) && is_array($contact['PHONE'])) {
            foreach ($contact['PHONE'] as $i => $p) {
                $v = $p['VALUE'] ?? '';
                if ($v !== '') {
                    $fm['PHONE']['n' . $i] = ['VALUE' => $v, 'VALUE_TYPE' => $p['VALUE_TYPE'] ?? 'WORK'];
                }
            }
        }
        if (!empty($contact['EMAIL']) && is_array($contact['EMAIL'])) {
            foreach ($contact['EMAIL'] as $i => $e) {
                $v = $e['VALUE'] ?? '';
                if ($v !== '') {
                    $fm['EMAIL']['n' . $i] = ['VALUE' => $v, 'VALUE_TYPE' => $e['VALUE_TYPE'] ?? 'WORK'];
                }
            }
        }
        if (!empty($fm)) {
            $item['FM'] = $fm;
        }

        $dateCreate = $contact['DATE_CREATE'] ?? null;
        $dateModify = $contact['DATE_MODIFY'] ?? null;

        $crm = new \CCrmContact(false);
        $boxId = (int)$crm->Add($item);
        if ($boxId <= 0) {
            return null;
        }

        if ($dateCreate || $dateModify) {
            $upd = [];
            if ($dateCreate) {
                $upd[] = "DATE_CREATE = '" . $DB->ForSql($dateCreate) . "'";
            }
            if ($dateModify) {
                $upd[] = "DATE_MODIFY = '" . $DB->ForSql($dateModify) . "'";
            }
            if ($upd !== []) {
                $DB->Query('UPDATE b_crm_contact SET ' . implode(', ', $upd) . ' WHERE ID = ' . $boxId);
            }
        }

        return $boxId;
    }

    public static function mappingHasCloudContact(array $fullMapping, int $cloudContactId): bool
    {
        $c = $fullMapping['contacts'] ?? [];

        return isset($c[(string)$cloudContactId]);
    }

    /**
     * Align box contact person fields with cloud when cloud has values and box differs.
     * Uses b_crm_contact directly: CCrmContact::GetListEx may hide rows in CLI despite NOT_CHECK_PERMISSIONS.
     */
    public static function syncPersonFieldsFromCloud(int $boxContactId, array $cloudContact): bool
    {
        if ($boxContactId <= 0) {
            return false;
        }
        global $DB;
        $row = $DB->Query(
            'SELECT ID, NAME, LAST_NAME, SECOND_NAME FROM b_crm_contact WHERE ID = ' . (int)$boxContactId
        )->Fetch();
        if (!$row) {
            return false;
        }
        $sets = [];
        foreach (['NAME', 'LAST_NAME', 'SECOND_NAME'] as $f) {
            $cv = trim((string)($cloudContact[$f] ?? ''));
            if ($cv === '') {
                continue;
            }
            $bv = trim((string)($row[$f] ?? ''));
            if ($bv !== $cv) {
                $sets[] = $f . " = '" . $DB->ForSql((string)$cloudContact[$f]) . "'";
            }
        }
        if ($sets === []) {
            return true;
        }

        $DB->Query(
            'UPDATE b_crm_contact SET ' . implode(', ', $sets) . ' WHERE ID = ' . (int)$boxContactId
        );

        return true;
    }
}
