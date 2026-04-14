<?php

declare(strict_types=1);

namespace UniversalSystem\V4\Handlers;

final class Bitrix24SourceDescriptionHandler extends AbstractHandler
{
    public function parse(array $request): array
    {
        $result = $this->defaultResult('bitrix24_source_description');
        $fields = $this->extractFields($request);
        $sourceDescription = trim((string)($fields['SOURCE_DESCRIPTION'] ?? ''));
        if ($sourceDescription === '') {
            return $result;
        }

        $phone = $this->normalizePhone((string)($fields['PHONE'] ?? ''));
        $name = trim((string)($fields['NAME'] ?? ''));
        $comment = trim((string)($fields['COMMENTS'] ?? ''));
        $leadTitle = trim((string)($fields['TITLE'] ?? ''));
        if ($leadTitle === '') {
            $leadTitle = 'Лид с сайта [' . $sourceDescription . ']';
        }

        $result['parsed_ok'] = true;
        $result['contact']['phone'] = $phone;
        $result['contact']['name'] = $name;
        $result['source']['domain'] = $sourceDescription;
        $result['source']['lookup_mode'] = 'source_description';
        $result['source']['lookup_key'] = $sourceDescription;
        $result['source']['source_description'] = 'Сайт: ' . $sourceDescription;
        $result['source']['lead_title'] = $leadTitle;
        $result['meta']['comment'] = $comment;
        $result['meta']['reason'] = 'source_description';

        return $result;
    }

    private function extractFields(array $request): array
    {
        $fields = [];
        $rawHeaders = isset($request['raw_headers']) && is_array($request['raw_headers']) ? $request['raw_headers'] : [];
        $queryString = (string)($rawHeaders['QUERY_STRING'] ?? '');
        if ($queryString !== '') {
            foreach ($this->extractFieldsFromEncodedString($queryString) as $key => $value) {
                $fields[$key] = $value;
            }
        }

        $rawBody = (string)($request['raw_body'] ?? '');
        if ($rawBody !== '') {
            foreach ($this->extractFieldsFromEncodedString($rawBody) as $key => $value) {
                if (!isset($fields[$key]) || $fields[$key] === '') {
                    $fields[$key] = $value;
                }
            }
        }

        return $fields;
    }

    private function extractFieldsFromEncodedString(string $value): array
    {
        $fields = [];
        $decoded = urldecode($value);
        if (!preg_match_all('/fields\[([^=]+)\]=([^&]*)/', $decoded, $matches, \PREG_SET_ORDER)) {
            return $fields;
        }

        foreach ($matches as $match) {
            $fieldName = $match[1];
            $fieldValue = trim((string)urldecode($match[2]));
            if ($fieldName === 'PHONE[0][VALUE]' || $fieldName === 'PHONE][0][VALUE') {
                $fields['PHONE'] = $fieldValue;
                continue;
            }
            $fields[$fieldName] = $fieldValue;
        }

        return $fields;
    }
}
