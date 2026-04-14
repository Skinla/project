<?php
/**
 * Copy to config/portal.php (not committed) and set URLs for this migration pair.
 * Used by scan_*, build_*, ContactBoxSync, SSH helpers, and mapping JSON metadata.
 */
return [
    /** Short id for logs / mapping files when several pairs exist, e.g. "acme-2025" */
    'portal_pair_id' => '',
    /** Cloud portal base URL, no trailing slash */
    'source_base_url' => 'https://YOUR_CLOUD.bitrix24.ru',
    /** Box public base URL (same host as CRM), no trailing slash */
    'box_base_url' => 'https://YOUR_BOX.example.com',
    /** Remote filesystem path to Bitrix site root (for scp/ssh cd) */
    'box_document_root' => '/home/bitrix/www',
];
