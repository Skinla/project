<?php
/**
 * Нормализация подписей UF из облака: в REST часто listLabel = код поля, а текст — в formLabel.
 *
 * @return array{0: string, 1: string, 2: string} list, form, filter
 */
function uf_normalize_cloud_labels(
    string $fieldName,
    string $list,
    string $form,
    string $filter,
    bool $beautifyUnderscores = true
): array {
    $list = trim($list);
    $form = trim($form);
    $filter = trim($filter);

    if ($list === $fieldName) {
        $list = '';
    }
    if ($filter === $fieldName) {
        $filter = '';
    }
    if ($form === $fieldName) {
        $form = '';
    }

    if ($list === '') {
        $list = $form !== '' ? $form : $filter;
    }
    if ($form === '') {
        $form = $list !== '' ? $list : $filter;
    }
    if ($filter === '') {
        $filter = $list;
    }

    if ($beautifyUnderscores) {
        $list = str_replace('_', ' ', $list);
        $form = str_replace('_', ' ', $form);
        $filter = str_replace('_', ' ', $filter);
    }

    return [$list, $form, $filter];
}
