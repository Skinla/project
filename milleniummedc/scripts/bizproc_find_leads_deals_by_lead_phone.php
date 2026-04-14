if (!\Bitrix\Main\Loader::includeModule('crm')) {
    $this->WriteToTrackingService('crm module not available');
    $this->SetVariable('List_ID_Lead', []);
    $this->SetVariable('List_ID_Deal', []);
    return;
}

$documentId = $this->GetDocumentId();
$leadId = 0;
if (is_array($documentId) && isset($documentId[2])) {
    if (preg_match('/(\d+)$/', (string)$documentId[2], $matches)) {
        $leadId = (int)$matches[1];
    }
}

if ($leadId <= 0) {
    $this->WriteToTrackingService('Could not resolve lead ID: ' . print_r($documentId, true));
    $this->SetVariable('List_ID_Lead', []);
    $this->SetVariable('List_ID_Deal', []);
    return;
}

$sourcePhoneLocalMap = [];
$sourcePhoneLog = [];

$phoneRes = \CCrmFieldMulti::GetList(
    ['ID' => 'ASC'],
    ['ENTITY_ID' => 'LEAD', 'ELEMENT_ID' => $leadId, 'TYPE_ID' => 'PHONE']
);
while ($phoneRow = $phoneRes->Fetch()) {
    $val = trim((string)($phoneRow['VALUE'] ?? ''));
    if ($val === '') {
        continue;
    }
    $digits = preg_replace('/\D+/', '', $val);
    if (!is_string($digits) || strlen($digits) < 10) {
        continue;
    }
    $sourcePhoneLocalMap[substr($digits, -10)] = true;
    $sourcePhoneLog[] = $val;
}

if ($sourcePhoneLocalMap === []) {
    $this->WriteToTrackingService('Lead #' . $leadId . ' has no valid phones');
    $this->SetVariable('List_ID_Lead', []);
    $this->SetVariable('List_ID_Deal', []);
    return;
}

$foundLeadMap = [];
$foundDealMap = [];
$foundContactMap = [];

foreach (['LEAD', 'DEAL', 'CONTACT'] as $entity) {
    $res = \CCrmFieldMulti::GetList(
        ['ID' => 'ASC'],
        ['ENTITY_ID' => $entity, 'TYPE_ID' => 'PHONE']
    );
    while ($row = $res->Fetch()) {
        $elementId = (int)($row['ELEMENT_ID'] ?? 0);
        $val = trim((string)($row['VALUE'] ?? ''));
        if ($elementId <= 0 || $val === '') {
            continue;
        }
        $digits = preg_replace('/\D+/', '', $val);
        if (!is_string($digits) || strlen($digits) < 10) {
            continue;
        }
        $local = substr($digits, -10);
        if (!isset($sourcePhoneLocalMap[$local])) {
            continue;
        }

        if ($entity === 'LEAD' && $elementId !== $leadId) {
            $foundLeadMap[$elementId] = $elementId;
        } elseif ($entity === 'DEAL') {
            $foundDealMap[$elementId] = $elementId;
        } elseif ($entity === 'CONTACT') {
            $foundContactMap[$elementId] = $elementId;
        }
    }
}

if ($foundContactMap !== []) {
    $dealRes = \CCrmDeal::GetListEx(
        ['ID' => 'ASC'],
        ['CONTACT_ID' => array_keys($foundContactMap), 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID']
    );
    while ($dealRes && ($dealRow = $dealRes->Fetch())) {
        $dealId = (int)($dealRow['ID'] ?? 0);
        if ($dealId > 0) {
            $foundDealMap[$dealId] = $dealId;
        }
    }
}

$foundLeadIds = array_map('intval', array_values($foundLeadMap));
sort($foundLeadIds, SORT_NUMERIC);

$foundDealIds = array_map('intval', array_values($foundDealMap));
sort($foundDealIds, SORT_NUMERIC);

$this->SetVariable('List_ID_Lead', $foundLeadIds);
$this->SetVariable('List_ID_Deal', $foundDealIds);

$this->WriteToTrackingService(
    'Lead #' . $leadId
    . ' phones: ' . implode(', ', $sourcePhoneLog)
    . ' | local: ' . implode(', ', array_keys($sourcePhoneLocalMap))
    . ' | leads: ' . implode(', ', $foundLeadIds)
    . ' | deals: ' . implode(', ', $foundDealIds)
);
