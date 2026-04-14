<?php
/**
 * Lead sync: cloud → box. Build payload for migrate_leads_from_json.php
 */
require_once dirname(__DIR__, 4) . '/lib/CrmActivityCloudEnrich.php';

class LeadSync
{
    /**
     * Build single lead payload (lead + activities + comments + products) for box.
     *
     * @param BitrixRestClient $client REST client for cloud
     * @param int $leadId Cloud lead ID
     * @param array $stageMapping lead_stages
     * @param array $fieldMapping lead_fields
     * @param array $userMapping users
     * @return array|null {lead, activities, comments, products} or null on error
     */
    public static function buildLeadPayload($client, int $leadId, array $stageMapping, array $fieldMapping, array $userMapping, array $contactMapping = [], array $sourceMapping = [], array $honorificMapping = []): ?array
    {
        $leadResp = $client->call('crm.lead.get', ['id' => $leadId]);
        if (isset($leadResp['error'])) {
            return null;
        }
        $lead = $leadResp['result'] ?? null;
        if (!$lead) {
            return null;
        }

        $mapped = [];
        $fm = [];
        $linkedCloudContactId = (int)($lead['CONTACT_ID'] ?? 0);
        foreach ($lead as $key => $value) {
            if ($value === null || $value === '') continue;
            $targetKey = $fieldMapping['lead_fields'][$key] ?? null;
            if ($targetKey === null || $key === 'ID') continue;
            if ($linkedCloudContactId > 0 && in_array($key, ['NAME', 'LAST_NAME', 'SECOND_NAME'], true)) {
                continue;
            }
            if ($key === 'STATUS_ID') $value = $stageMapping['lead_stages'][$value] ?? $value;
            if ($key === 'SOURCE_ID' && !empty($sourceMapping['sources'])) $value = $sourceMapping['sources'][$value] ?? $value;
            if ($key === 'HONORIFIC' && !empty($honorificMapping['honorific'])) $value = $honorificMapping['honorific'][$value] ?? $value;
            if (in_array($key, ['ASSIGNED_BY_ID', 'CREATED_BY_ID', 'MODIFY_BY_ID']) && is_numeric($value)) {
                $value = $userMapping['users'][(string)$value] ?? 1;
            }
            if ($key === 'CONTACT_ID' && is_numeric($value)) {
                $value = $contactMapping['contacts'][(string)$value] ?? null;
                if ($value === null) continue;
            }
            if ($key === 'PHONE' && is_array($value)) {
                foreach ($value as $i => $item) {
                    if (!empty($item['VALUE'])) {
                        $fm['PHONE']['n' . $i] = ['VALUE' => $item['VALUE'], 'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'];
                    }
                }
                continue;
            }
            if ($key === 'EMAIL' && is_array($value)) {
                foreach ($value as $i => $item) {
                    if (!empty($item['VALUE'])) {
                        $fm['EMAIL']['n' . $i] = ['VALUE' => $item['VALUE'], 'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'];
                    }
                }
                continue;
            }
            if (in_array($key, ['WEB', 'IM']) && is_array($value)) {
                foreach ($value as $i => $item) {
                    if (!empty($item['VALUE'])) {
                        $fm[$key]['n' . $i] = ['VALUE' => $item['VALUE'], 'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'];
                    }
                }
                continue;
            }
            $mapped[$targetKey] = $value;
        }
        if (!empty($lead['CONTACT_ID']) && (empty($fm['PHONE']) || empty($fm['EMAIL']))) {
            $contactResp = $client->call('crm.contact.get', ['id' => $lead['CONTACT_ID']]);
            if (!isset($contactResp['error']) && !empty($contactResp['result'])) {
                $contact = $contactResp['result'];
                if (empty($fm['PHONE']) && !empty($contact['PHONE']) && is_array($contact['PHONE'])) {
                    foreach ($contact['PHONE'] as $i => $item) {
                        if (!empty($item['VALUE'])) {
                            $fm['PHONE']['n' . $i] = ['VALUE' => $item['VALUE'], 'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'];
                        }
                    }
                }
                if (empty($fm['EMAIL']) && !empty($contact['EMAIL']) && is_array($contact['EMAIL'])) {
                    foreach ($contact['EMAIL'] as $i => $item) {
                        if (!empty($item['VALUE'])) {
                            $fm['EMAIL']['n' . $i] = ['VALUE' => $item['VALUE'], 'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'];
                        }
                    }
                }
            }
        }
        if (!empty($fm)) {
            $mapped['FM'] = $fm;
        }

        $activities = [];
        $actResp = $client->call('crm.activity.list', [
            'filter' => ['OWNER_TYPE_ID' => 1, 'OWNER_ID' => $leadId],
            'select' => ['*', 'COMMUNICATIONS'],
        ]);
        foreach ($actResp['result'] ?? [] as $act) {
            if (is_array($act)) {
                $act = CrmActivityCloudEnrich::mergeFullFieldsFromGet($client, $act);
            }
            $cloudActId = $act['ID'] ?? null;
            $act['RESPONSIBLE_ID'] = $userMapping['users'][(string)($act['RESPONSIBLE_ID'] ?? '')] ?? 1;
            $act['AUTHOR_ID'] = $userMapping['users'][(string)($act['AUTHOR_ID'] ?? '')] ?? 1;
            $act['EDITOR_ID'] = $userMapping['users'][(string)($act['EDITOR_ID'] ?? '')] ?? 1;
            unset($act['ID'], $act['OWNER_ID']);
            $act['cloud_activity_id'] = $cloudActId;
            $activities[] = $act;
        }

        $comments = [];
        $comResp = $client->call('crm.timeline.comment.list', [
            'filter' => ['ENTITY_ID' => $leadId, 'ENTITY_TYPE' => 'lead'],
            'select' => ['ID', 'CREATED', 'AUTHOR_ID', 'COMMENT', 'FILES'],
        ]);
        foreach ($comResp['result'] ?? [] as $com) {
            $com['AUTHOR_ID'] = $userMapping['users'][(string)($com['AUTHOR_ID'] ?? '')] ?? 1;
            unset($com['ID']);
            $comments[] = $com;
        }

        $products = [];
        $prodResp = $client->call('crm.lead.productrows.get', ['id' => $leadId]);
        foreach ($prodResp['result'] ?? [] as $p) {
            unset($p['ID'], $p['OWNER_ID']);
            $products[] = $p;
        }

        return [
            'lead' => $mapped,
            'activities' => $activities,
            'comments' => $comments,
            'products' => $products,
        ];
    }
}
