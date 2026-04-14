<?php

declare(strict_types=1);

namespace UniversalSystem\V4\Handlers;

final class BushuevaSupplierHandler extends AbstractHandler
{
    public function parse(array $request): array
    {
        $result = $this->defaultResult('bushueva_supplier');
        $payload = $this->payload($request);
        $assignedById = trim((string)($payload['ASSIGNED_BY_ID'] ?? $payload['assigned_by_id'] ?? ''));
        if ($assignedById === '' || mb_strpos($assignedById, 'Заявка от Bushueva') !== 0) {
            return $result;
        }

        $siteKey = explode('_', $assignedById, 2)[0];
        $phone = $this->normalizePhone((string)($payload['PHONE'] ?? $payload['Phone'] ?? $payload['phone'] ?? ''));
        $name = trim((string)($payload['NAME'] ?? $payload['Name'] ?? $payload['name'] ?? ''));
        $comment = trim((string)($payload['comments'] ?? $payload['COMMENTS'] ?? ''));

        $result['parsed_ok'] = true;
        $result['contact']['phone'] = $phone;
        $result['contact']['name'] = $name;
        $result['source']['domain'] = $assignedById;
        $result['source']['lookup_mode'] = 'domain';
        $result['source']['lookup_key'] = $assignedById;
        $result['source']['source_description'] = 'Сайт: ' . $assignedById;
        $result['source']['lead_title'] = 'Лид с сайта [' . $assignedById . ']';
        $result['meta']['comment'] = $comment;
        $result['meta']['reason'] = 'assigned_by_prefix_bushueva';
        $result['meta']['site_key'] = $siteKey;

        return $result;
    }
}
