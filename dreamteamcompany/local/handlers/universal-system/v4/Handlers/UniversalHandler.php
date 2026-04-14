<?php

declare(strict_types=1);

namespace UniversalSystem\V4\Handlers;

final class UniversalHandler extends AbstractHandler
{
    public function parse(array $request): array
    {
        $result = $this->defaultResult('universal');
        $payload = $this->payload($request);
        $domain = trim((string)($request['source_domain'] ?? $payload['source_domain'] ?? 'unknown.domain'));

        $phone = '';
        foreach (['Phone', 'phone', 'PHONE'] as $field) {
            if (!empty($payload[$field])) {
                $phone = $this->normalizePhone((string)$payload[$field]);
                break;
            }
        }
        if ($phone === '' && !empty($payload['contacts']['phone'])) {
            $phone = $this->normalizePhone((string)$payload['contacts']['phone']);
        }
        if ($phone === '' && !empty($payload['callerphone'])) {
            $phone = $this->normalizePhone((string)$payload['callerphone']);
        }

        $name = '';
        foreach (['name', 'Name', 'NAME', 'fio', 'FIO', 'fullname'] as $field) {
            if (!empty($payload[$field]) && is_string($payload[$field])) {
                $candidate = trim($payload[$field]);
                if ($candidate !== '' && strlen($candidate) < 100 && !preg_match('/^\d+$/', $candidate)) {
                    $name = $candidate;
                    break;
                }
            }
        }
        if ($name === '' && !empty($payload['contacts']['name'])) {
            $name = trim((string)$payload['contacts']['name']);
        }

        $commentLines = [];
        if (!empty($payload['answers']) && is_array($payload['answers'])) {
            foreach ($payload['answers'] as $answer) {
                if (!is_array($answer)) {
                    continue;
                }
                $question = trim((string)($answer['q'] ?? ''));
                $value = $answer['a'] ?? '';
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $value = trim((string)$value);
                if ($question !== '' && $value !== '') {
                    $commentLines[] = $question . ': ' . $value;
                }
            }
        } else {
            foreach ($payload as $key => $value) {
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }
                if (in_array($key, ['Phone', 'phone', 'PHONE', 'name', 'Name', 'NAME', 'source_domain', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'], true)) {
                    continue;
                }
                $commentLines[] = str_replace('_', ' ', (string)$key) . ': ' . trim($value);
            }
        }

        $isCall = !empty($payload['callerphone']) || !empty($payload['subPoolName']) || !empty($payload['siteId']);
        $result['parsed_ok'] = true;
        $result['contact']['phone'] = $phone;
        $result['contact']['name'] = $name;
        $result['contact']['last_name'] = trim((string)($payload['last_name'] ?? $payload['surname'] ?? $payload['LAST_NAME'] ?? ''));
        $result['contact']['second_name'] = trim((string)($payload['second_name'] ?? $payload['middle_name'] ?? $payload['SECOND_NAME'] ?? ''));
        $result['source']['domain'] = $domain;
        $result['source']['lookup_mode'] = 'domain';
        $result['source']['lookup_key'] = $domain;
        $result['source']['source_description'] = $isCall ? 'CallTouch' : ('Сайт: ' . $domain);
        $result['source']['lead_title'] = ($isCall ? 'Звонок' : 'Лид') . ' с сайта [' . $domain . ']';
        $result['meta']['comment'] = implode("\n", $commentLines);
        $result['meta']['utm'] = $this->buildUtm($payload);
        $result['meta']['reason'] = 'recognized_webhook_shape';

        return $result;
    }
}
