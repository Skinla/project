<?php

declare(strict_types=1);

final class B24Client
{
    private readonly string $webhookBase;

    public function __construct(string $webhookBase)
    {
        $this->webhookBase = rtrim($webhookBase, '/') . '/';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $url = $this->webhookBase . $method;
        $payload = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->httpPostJson($url, $payload);
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (isset($decoded['error'])) {
            $desc = isset($decoded['error_description']) ? (string) $decoded['error_description'] : '';
            throw new RuntimeException(
                'Bitrix REST error: ' . (string) $decoded['error'] . ($desc !== '' ? ' — ' . $desc : '')
            );
        }

        return $decoded;
    }

    private function httpPostJson(string $url, string $json): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('curl_init failed');
            }
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            $body = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false) {
                throw new RuntimeException('HTTP request failed: ' . $err);
            }
            if ($code < 200 || $code >= 300) {
                throw new RuntimeException('HTTP status ' . $code . ': ' . $body);
            }
            return $body;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $json,
                'timeout' => 25,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException('file_get_contents HTTP failed');
        }
        return $body;
    }
}

final class StatusResolver
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config
    ) {
    }

    /**
     * Коды из CRM: SOURCE_ID и STAGE_ID (без запросов к API).
     *
     * @return array{source_id: string, stage_id: string}
     */
    public function resolveSourceAndStage(): array
    {
        $sourceId = trim((string) ($this->config['source_id'] ?? ''));
        $stageId = trim((string) ($this->config['stage_id'] ?? ''));
        if ($sourceId === '') {
            throw new RuntimeException('В config задайте source_id — код источника (SOURCE_ID), например WEB');
        }
        if ($stageId === '') {
            throw new RuntimeException('В config задайте stage_id — код стадии (STATUS_ID), например C1:NEW');
        }

        return ['source_id' => $sourceId, 'stage_id' => $stageId];
    }
}

final class TildaPayload
{
    /** @var list<string> */
    private const KEY_NAME = ['Name', 'name', 'NAME', 'ФИО', 'FIO'];
    /** @var list<string> */
    private const KEY_EMAIL = ['Email', 'email', 'E-mail', 'E-mail ', 'MAIL'];
    /** @var list<string> */
    private const KEY_PHONE = ['Phone', 'phone', 'Телефон', 'Mobile', 'Tel'];
    /** @var list<string> */
    private const KEY_COMMENTS = [
        'Comments', 'comments', 'Comment', 'Message', 'Сообщение', 'Text',
        'Комментарий', 'комментарий', 'COMMENT',
    ];
    /** @var list<string> */
    private const SYSTEM_KEYS = ['tranid', 'formid', 'COOKIES', 'test'];
    /** @var list<string> */
    private const UTM_POST_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    /**
     * @param array<string, string> $post
     */
    public static function isTildaConnectionTest(array $post): bool
    {
        return count($post) === 1
            && isset($post['test'])
            && (string) $post['test'] === 'test';
    }

    /**
     * @param array<string, string> $post
     * @return array<string, string>
     */
    public static function normalize(array $post): array
    {
        $out = [];
        foreach ($post as $key => $value) {
            $k = is_string($key) ? $key : (string) $key;
            $out[$k] = is_string($value) ? rawurldecode($value) : (string) $value;
        }
        return $out;
    }

    /**
     * @param array<string, string> $data
     */
    public static function buildTitle(array $data): string
    {
        $name = self::getFirst($data, self::KEY_NAME);
        $tran = $data['tranid'] ?? '';
        if ($name !== '') {
            return 'Заявка: ' . $name;
        }
        $base = 'Заявка с сайта';
        return $tran !== '' ? $base . ' [' . $tran . ']' : $base;
    }

    /**
     * @param array<string, string> $data
     * @return array<string, string>
     */
    public static function extractUtm(array $data): array
    {
        $bitrixKeys = [
            'utm_source' => 'UTM_SOURCE',
            'utm_medium' => 'UTM_MEDIUM',
            'utm_campaign' => 'UTM_CAMPAIGN',
            'utm_content' => 'UTM_CONTENT',
            'utm_term' => 'UTM_TERM',
        ];

        $fromCookie = self::parseUtmFromTildaCookies($data['COOKIES'] ?? '');
        $out = $fromCookie;

        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $lk = mb_strtolower($key, 'UTF-8');
            if (!isset($bitrixKeys[$lk])) {
                continue;
            }
            $val = trim((string) $value);
            if ($val !== '') {
                $out[$bitrixKeys[$lk]] = $val;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function parseUtmFromTildaCookies(string $cookiesRaw): array
    {
        $cookiesRaw = trim($cookiesRaw);
        if ($cookiesRaw === '') {
            return [];
        }

        $decoded = $cookiesRaw;
        for ($i = 0; $i < 8; $i++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        $tildePayload = '';
        if (preg_match('/(?:^|[;\s])TILDAUTM=([^;]*)/i', $decoded, $m)) {
            $tildePayload = trim($m[1]);
        }

        if ($tildePayload === '') {
            return [];
        }

        for ($i = 0; $i < 6; $i++) {
            $next = rawurldecode($tildePayload);
            if ($next === $tildePayload) {
                break;
            }
            $tildePayload = $next;
        }

        $map = [
            'utm_source' => 'UTM_SOURCE',
            'utm_medium' => 'UTM_MEDIUM',
            'utm_campaign' => 'UTM_CAMPAIGN',
            'utm_content' => 'UTM_CONTENT',
            'utm_term' => 'UTM_TERM',
        ];

        $out = [];
        foreach (explode('|||', $tildePayload) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || !str_contains($segment, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $segment, 2);
            $k = mb_strtolower(trim($k), 'UTF-8');
            $v = trim($v);
            if (isset($map[$k]) && $v !== '') {
                $out[$map[$k]] = $v;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $data
     * @return array{
     *   contact_fields: array<string, mixed>,
     *   has_contact_payload: bool,
     *   deal_comments: string,
     *   source_description: string
     * }
     */
    public static function extractCrmData(array $data, ?string $referer, ?string $secretFieldName): array
    {
        $nameFull = self::getFirst($data, self::KEY_NAME);
        $email = self::getFirst($data, self::KEY_EMAIL);
        $phoneRaw = self::getFirst($data, self::KEY_PHONE);
        $formComment = self::getFirst($data, self::KEY_COMMENTS);

        [$firstName, $lastName] = self::splitFullName($nameFull);

        $phone = self::normalizePhone($phoneRaw);

        $contactFields = [
            'OPENED' => 'Y',
        ];
        if ($firstName !== '' || $lastName !== '') {
            $contactFields['NAME'] = $firstName !== '' ? $firstName : $nameFull;
            if ($lastName !== '') {
                $contactFields['LAST_NAME'] = $lastName;
            }
        }
        if ($phone !== '') {
            $contactFields['PHONE'] = [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']];
        }
        if ($email !== '') {
            $contactFields['EMAIL'] = [['VALUE' => $email, 'VALUE_TYPE' => 'WORK']];
        }

        $hasContact = isset($contactFields['NAME'])
            || isset($contactFields['PHONE'])
            || isset($contactFields['EMAIL']);

        if ($hasContact && !isset($contactFields['NAME'])) {
            if ($email !== '') {
                $local = explode('@', $email, 2)[0];
                $contactFields['NAME'] = $local !== '' ? $local : 'Клиент';
            } else {
                $contactFields['NAME'] = 'Клиент';
            }
        }

        $extras = self::collectExtras($data, $secretFieldName);
        $dealComments = [];
        if ($formComment !== '') {
            $dealComments[] = $formComment;
        }
        if ($extras !== []) {
            $dealComments[] = 'Дополнительно:';
            foreach ($extras as $k => $v) {
                $dealComments[] = $k . ': ' . $v;
            }
        }

        $src = [];
        if (isset($data['tranid']) && (string) $data['tranid'] !== '') {
            $src[] = 'Tilda tranid: ' . $data['tranid'];
        }
        if (isset($data['formid']) && (string) $data['formid'] !== '') {
            $src[] = 'formid: ' . $data['formid'];
        }
        if ($referer !== null && $referer !== '') {
            $src[] = 'Referer: ' . $referer;
        }

        $dealCommentsText = implode("\n", array_filter($dealComments, static fn ($l) => $l !== ''));
        if ($dealCommentsText === '') {
            $dealCommentsText = self::buildFallbackDealComments($data);
        }

        return [
            'contact_fields' => $contactFields,
            'has_contact_payload' => $hasContact,
            'deal_comments' => $dealCommentsText,
            'source_description' => implode("\n", $src),
        ];
    }

    /**
     * Текст для поля сделки «Комментарий», если в форме не было отдельного поля комментария и доп. полей.
     *
     * @param array<string, string> $data
     */
    public static function buildFallbackDealComments(array $data): string
    {
        $lines = [];
        $name = self::getFirst($data, self::KEY_NAME);
        $email = self::getFirst($data, self::KEY_EMAIL);
        $phone = self::normalizePhone(self::getFirst($data, self::KEY_PHONE));
        if ($name !== '') {
            $lines[] = 'Имя: ' . $name;
        }
        if ($email !== '') {
            $lines[] = 'E-mail: ' . $email;
        }
        if ($phone !== '') {
            $lines[] = 'Телефон: ' . $phone;
        }
        if (isset($data['tranid']) && (string) $data['tranid'] !== '') {
            $lines[] = 'tranid: ' . $data['tranid'];
        }
        if (isset($data['formid']) && (string) $data['formid'] !== '') {
            $lines[] = 'formid: ' . $data['formid'];
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $data
     * @return array<string, string>
     */
    private static function collectExtras(array $data, ?string $secretFieldName): array
    {
        $skip = array_merge(
            self::SYSTEM_KEYS,
            self::KEY_NAME,
            self::KEY_EMAIL,
            self::KEY_PHONE,
            self::KEY_COMMENTS,
            self::UTM_POST_KEYS
        );
        $skipLower = array_map(static fn (string $s) => mb_strtolower($s, 'UTF-8'), $skip);
        if ($secretFieldName !== null && $secretFieldName !== '') {
            $skipLower[] = mb_strtolower($secretFieldName, 'UTF-8');
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $lk = mb_strtolower($key, 'UTF-8');
            if (in_array($lk, $skipLower, true)) {
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * @param list<string> $candidates
     */
    private static function getFirst(array $data, array $candidates): string
    {
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            foreach ($candidates as $want) {
                if (strcasecmp($key, $want) === 0) {
                    return trim((string) $value);
                }
            }
        }
        return '';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitFullName(string $full): array
    {
        $full = trim($full);
        if ($full === '') {
            return ['', ''];
        }
        if (!preg_match('/^(\S+)\s+(.+)$/u', $full, $m)) {
            return [$full, ''];
        }
        return [trim($m[1]), trim($m[2])];
    }

    private static function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }
        return '+' . $digits;
    }
}

final class WebhookLogger
{
    private const MAX_LOG_BYTES = 2097152;

    public function __construct(
        private readonly string $logDir
    ) {
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function log(array $entry): void
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0775, true);
        }
        $file = $this->logDir . '/tilda-' . gmdate('Y-m-d') . '.log';
        $entry['ts'] = gmdate('c');
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        $size = is_file($file) ? (int) filesize($file) : 0;
        $len = strlen($line);
        $over = $size >= self::MAX_LOG_BYTES || $size + $len > self::MAX_LOG_BYTES;
        $flags = $over ? LOCK_EX : (FILE_APPEND | LOCK_EX);
        @file_put_contents($file, $line, $flags);
    }
}

if (defined('TILDA_SKIP_WEBHOOK_RUN') && TILDA_SKIP_WEBHOOK_RUN) {
    return;
}

header('Access-Control-Allow-Origin: *');

define('TILDA_CONFIG_INCLUDE', true);
/** @var array<string, mixed> $config */
$config = require __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'method not allowed';
    exit;
}

$logDir = (string) $config['log_dir'];
$logger = new WebhookLogger($logDir);

/** @var array<string, string> $post */
$post = $_POST;

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$logBase = [
    'ip' => $ip,
    'post_keys' => array_keys($post),
];

$secret = $config['tilda_shared_secret'] ?? null;
if ($secret !== null && $secret !== '') {
    $field = (string) ($config['tilda_secret_post_field'] ?? 'api_key');
    if (!isset($post[$field]) || (string) $post[$field] !== (string) $secret) {
        http_response_code(403);
        $logger->log($logBase + ['error' => 'forbidden', 'detail' => 'invalid_tilda_secret']);
        echo 'forbidden';
        exit;
    }
}

if (TildaPayload::isTildaConnectionTest($post)) {
    $logger->log($logBase + ['skipped_test' => true]);
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(200);
    echo 'ok';
    exit;
}

$normalized = TildaPayload::normalize($post);

$secretField = null;
$sec = $config['tilda_shared_secret'] ?? null;
if ($sec !== null && $sec !== '') {
    $secretField = (string) ($config['tilda_secret_post_field'] ?? 'api_key');
}

try {
    $b24 = new B24Client((string) $config['bitrix_webhook_base']);
    $resolver = new StatusResolver($config);
    $ids = $resolver->resolveSourceAndStage();

    $crm = TildaPayload::extractCrmData(
        $normalized,
        isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : null,
        $secretField
    );

    $utm = TildaPayload::extractUtm($normalized);

    $contactId = null;
    if ($crm['has_contact_payload']) {
        $cf = $crm['contact_fields'];
        $cf['ASSIGNED_BY_ID'] = (int) $config['assigned_by_id'];
        $cf['SOURCE_ID'] = $ids['source_id'];
        $added = $b24->call('crm.contact.add', ['fields' => $cf]);
        $contactId = isset($added['result']) ? (int) $added['result'] : null;
    }

    $comments = $crm['deal_comments'];
    if ($comments === '') {
        $comments = 'Заявка с сайта';
    }

    $fields = [
        'TITLE' => TildaPayload::buildTitle($normalized),
        'COMMENTS' => $comments,
        'CATEGORY_ID' => (int) $config['category_id'],
        'STAGE_ID' => $ids['stage_id'],
        'SOURCE_ID' => $ids['source_id'],
        'ASSIGNED_BY_ID' => (int) $config['assigned_by_id'],
    ];
    if ($crm['source_description'] !== '') {
        $fields['SOURCE_DESCRIPTION'] = $crm['source_description'];
    }
    if ($contactId !== null && $contactId > 0) {
        $fields['CONTACT_IDS'] = [$contactId];
    }

    foreach ($utm as $utmField => $utmValue) {
        $fields[$utmField] = $utmValue;
    }

    $add = $b24->call('crm.deal.add', ['fields' => $fields]);
    $dealId = $add['result'] ?? null;

    $logger->log($logBase + [
        'deal_id' => $dealId,
        'contact_id' => $contactId,
        'source_id' => $ids['source_id'],
        'stage_id' => $ids['stage_id'],
        'utm_keys' => array_keys($utm),
    ]);

    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(200);
    echo 'ok';
} catch (Throwable $e) {
    $logger->log($logBase + [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo 'error';
}
