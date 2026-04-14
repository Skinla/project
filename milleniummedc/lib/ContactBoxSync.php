<?php
/**
 * Создание на коробке контактов из облака и обновление data/contact_mapping.json
 * (тот же контракт, что migrate_contacts_from_leads.php + create_contacts_on_box.php).
 */
class ContactBoxSync
{
    /**
     * ID контактов из сделки облака: CONTACT_ID и элементы CONTACT_IDS.
     *
     * @param array<string, mixed> $deal crm.deal.get result
     * @return int[]
     */
    public static function collectContactIdsFromDeal(array $deal): array
    {
        $ids = [];
        $cid = (int)($deal['CONTACT_ID'] ?? 0);
        if ($cid > 0) {
            $ids[] = $cid;
        }
        $multi = $deal['CONTACT_IDS'] ?? null;
        if (is_array($multi)) {
            foreach ($multi as $item) {
                if (is_numeric($item)) {
                    $i = (int)$item;
                    if ($i > 0) {
                        $ids[] = $i;
                    }
                } elseif (is_array($item)) {
                    $i = (int)($item['CONTACT_ID'] ?? $item['ID'] ?? 0);
                    if ($i > 0) {
                        $ids[] = $i;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Для каждого cloud contact id, которого нет в contact_mapping.json: crm.contact.get → create_contacts_on_box.php по SSH.
     * Обновляет файл на диске, копирует в local/handlers/leadsync/data и заливает на коробку.
     *
     * @return array<string, int> полный словарь contacts после merge
     */
    public static function ensureCloudContactsMapped(
        BitrixRestClient $client,
        array $cloudContactIds,
        array $userMapping,
        string $projectRoot,
        array $sshConfig
    ): array {
        $contactMappingPath = $projectRoot . '/data/contact_mapping.json';
        $file = file_exists($contactMappingPath) ? json_decode(file_get_contents($contactMappingPath), true) : [];
        if (!is_array($file)) {
            $file = [];
        }
        $existing = $file['contacts'] ?? [];
        if (!is_array($existing) || isset($existing[0])) {
            $existing = [];
        }

        $cloudContactIds = array_values(array_unique(array_filter(array_map('intval', $cloudContactIds))));
        $missing = [];
        foreach ($cloudContactIds as $id) {
            if ($id > 0 && !isset($existing[(string)$id])) {
                $missing[] = $id;
            }
        }

        if ($missing === []) {
            return $existing;
        }

        $payload = [];
        foreach ($missing as $cloudContactId) {
            $resp = $client->call('crm.contact.get', ['id' => $cloudContactId]);
            if (isset($resp['error']) || empty($resp['result'])) {
                continue;
            }
            $c = $resp['result'];
            $assignedById = $userMapping['users'][(string)($c['ASSIGNED_BY_ID'] ?? 1)] ?? 1;
            $payload[] = [
                'cloud_id' => $cloudContactId,
                'NAME' => $c['NAME'] ?? '',
                'LAST_NAME' => $c['LAST_NAME'] ?? '',
                'SECOND_NAME' => $c['SECOND_NAME'] ?? '',
                'POST' => $c['POST'] ?? '',
                'COMMENTS' => $c['COMMENTS'] ?? '',
                'ASSIGNED_BY_ID' => $assignedById,
                'PHONE' => $c['PHONE'] ?? [],
                'EMAIL' => $c['EMAIL'] ?? [],
                'DATE_CREATE' => $c['DATE_CREATE'] ?? null,
                'DATE_MODIFY' => $c['DATE_MODIFY'] ?? null,
            ];
        }

        if ($payload === []) {
            return $existing;
        }

        $host = $sshConfig['host'] ?? '185.51.60.122';
        $port = (int)($sshConfig['port'] ?? 2226);
        $user = $sshConfig['user'] ?? 'root';
        $pass = $sshConfig['password'] ?? '';

        $boxScript = $projectRoot . '/create_contacts_on_box.php';
        $scpCmd = $pass !== ''
            ? sprintf('sshpass -p %s scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/home/bitrix/www/', escapeshellarg($pass), $port, escapeshellarg($boxScript), $user, $host)
            : sprintf('scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/home/bitrix/www/', $port, escapeshellarg($boxScript), $user, $host);
        exec($scpCmd . ' 2>&1');

        $tmpFile = sys_get_temp_dir() . '/ensure_contacts_' . getmypid() . '_' . mt_rand() . '.json';
        file_put_contents($tmpFile, json_encode($payload, JSON_UNESCAPED_UNICODE));

        $sshCmd = $pass !== ''
            ? sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php create_contacts_on_box.php" < %s', escapeshellarg($pass), $port, $user, $host, escapeshellarg($tmpFile))
            : sprintf('ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php create_contacts_on_box.php" < %s', $port, $user, $host, escapeshellarg($tmpFile));

        $output = shell_exec($sshCmd);
        @unlink($tmpFile);

        $result = json_decode(trim($output ?? ''), true);
        if (!$result || isset($result['error'])) {
            return $existing;
        }

        $newMapping = $result['mapping'] ?? [];
        if (!is_array($newMapping)) {
            return $existing;
        }

        $merged = $existing;
        foreach ($newMapping as $k => $v) {
            $merged[(string)$k] = (int)$v;
        }

        $file['contacts'] = $merged;
        $file['created_at'] = date('c');
        if (empty($file['source_url'])) {
            $file['source_url'] = 'https://milleniummed.bitrix24.ru';
        }
        if (empty($file['target_url'])) {
            $file['target_url'] = 'https://bitrix.milleniummedc.ru';
        }
        file_put_contents($contactMappingPath, json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $handlerDataDir = $projectRoot . '/local/handlers/leadsync/data';
        if (!is_dir($handlerDataDir)) {
            @mkdir($handlerDataDir, 0755, true);
        }
        copy($contactMappingPath, $handlerDataDir . '/contact_mapping.json');

        $scpData = $pass !== ''
            ? sprintf('sshpass -p %s scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/home/bitrix/www/local/handlers/leadsync/data/', escapeshellarg($pass), $port, escapeshellarg($contactMappingPath), $user, $host)
            : sprintf('scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/home/bitrix/www/local/handlers/leadsync/data/', $port, escapeshellarg($contactMappingPath), $user, $host);
        exec($scpData . ' 2>&1');

        return $merged;
    }
}
