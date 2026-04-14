<?php
// normalizers/marquiz_normalizer.php
// Нормализатор для форм Marquiz

require_once __DIR__ . '/generic_normalizer.php';

class MarquizNormalizer extends GenericNormalizer {
    private function readNestedValue($data, $segments) {
        $current = $data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }

            $current = $current[$segment];
        }

        return trim((string)$current);
    }

    private function extractHost($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }

        return $value;
    }

    public function normalize($rawData) {
        $normalized = parent::normalize($rawData);

        if ($normalized === null) {
            return null;
        }

        $parsedData = $rawData['parsed_data'] ?? $rawData;
        $formId = trim((string)($parsedData['formid'] ?? $this->readNestedValue($parsedData, ['form', 'id'])));
        $quizId = $this->readNestedValue($parsedData, ['quiz', 'id']);
        $tranId = trim((string)($parsedData['tranid'] ?? ''));
        $formName = trim((string)($parsedData['formname'] ?? $parsedData['form_name'] ?? $this->readNestedValue($parsedData, ['quiz', 'name'])));
        $hrefHost = $this->extractHost($this->readNestedValue($parsedData, ['extra', 'href']));
        $referrerHost = $this->extractHost($this->readNestedValue($parsedData, ['extra', 'referrer']));

        if ($formId !== '') {
            $normalized['marquiz_form_id'] = $formId;
        }

        if ($quizId !== '') {
            $normalized['marquiz_quiz_id'] = $quizId;
        }

        if ($tranId !== '') {
            $normalized['marquiz_tranid'] = $tranId;
        }

        if ($formName !== '') {
            $normalized['form_name'] = $formName;
        }

        if ($hrefHost !== '') {
            $normalized['marquiz_href_host'] = $hrefHost;
        }

        if ($referrerHost !== '') {
            $normalized['marquiz_referrer_host'] = $referrerHost;
        }

        if (empty($normalized['source_domain']) || $normalized['source_domain'] === 'unknown' || $normalized['source_domain'] === 'unknown.domain') {
            foreach ([$formId, $quizId, $hrefHost, $referrerHost] as $candidateSourceDomain) {
                if ($candidateSourceDomain !== '') {
                    $normalized['source_domain'] = $candidateSourceDomain;
                    break;
                }
            }
        }

        logMessage(
            "NORMALIZER_RESULT | Type: marquiz | Phone: '" . ($normalized['phone'] ?? '') . "' | FormID: '" . $formId . "' | QuizID: '" . $quizId . "' | FormName: '" . ($normalized['form_name'] ?? '') . "'",
            $this->config['global_log'],
            $this->config
        );

        return $normalized;
    }
}
