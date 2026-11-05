<?php

declare(strict_types=1);

namespace App\Ai\Evaluation;

/** Offline acceptance checks of observed facts. Prose quality still requires independent review. */
final class JourneyAudit
{
    public function analyze(array $results, array $expected): array
    {
        $indexed = [];
        $duplicates = [];
        foreach ($results as $result) {
            $id = $result['id'] ?? '';
            if (isset($indexed[$id])) {
                $duplicates[] = $id;
            }
            $indexed[$id] = $result;
        }
        $dialogs = [];
        foreach ($expected as $case) {
            $result = $indexed[$case['id']] ?? null;
            $issues = $result === null ? ['RESULT_MISSING'] : $this->issues($result, $case);
            $dialogs[] = ['id' => $case['id'], 'passed' => $issues === [], 'issues' => array_values(array_unique($issues))];
        }
        $unknown = array_values(array_diff(array_keys($indexed), array_column($expected, 'id')));

        return ['schema_version' => 1, 'expected_dialogs' => count($expected), 'observed_dialogs' => count($results),
            'passed_dialogs' => count(array_filter($dialogs, fn ($row) => $row['passed'])),
            'dataset_valid' => $duplicates === [] && $unknown === [] && count($results) === count($expected),
            'duplicate_ids' => $duplicates, 'unexpected_ids' => $unknown, 'dialogs' => $dialogs,
            'human_quality' => 'not_scored', 'release_ready' => false];
    }

    private function issues(array $result, array $expected): array
    {
        $issues = [];
        if (($result['messages'] ?? null) !== $expected['messages'] || ($result['locale'] ?? null) !== $expected['locale']
            || ($result['buyer'] ?? null) !== $expected['buyer']) {
            $issues[] = 'CASE_INPUT_CHANGED';
        }
        $turns = $result['turns'] ?? [];
        if (count($turns) !== count($expected['messages'])) {
            $issues[] = 'TURN_MISSING';
        }
        foreach ($turns as $turn) {
            if (($turn['status'] ?? '') !== 'completed' || trim($turn['reply'] ?? '') === '') {
                $issues[] = 'REPLY_NOT_COMPLETED';
            }
            if (! isset($turn['provider_requests']) || $turn['provider_requests'] > 2 || $turn['provider_requests'] < 0) {
                $issues[] = 'PHYSICAL_REQUEST_BUDGET';
            }
            if (! isset($turn['runtime_seconds']) || $turn['runtime_seconds'] > 30) {
                $issues[] = 'TURN_DEADLINE';
            }
            if (($turn['new_orders'] ?? null) !== 0) {
                $issues[] = 'UNCONFIRMED_ORDER_OR_MISSING_EVIDENCE';
            }
        }
        $state = $result['state'] ?? [];
        $intent = $state['intent'] ?? [];
        $family = preg_replace('/^(ru|kk)-(retail|wholesale)-/', '', $expected['id']);
        $facts = match ($family) {
            'no-camera', 'camera-correction' => ['options.camera' => false],
            'camera' => ['options.camera' => true],
            'rain' => ['options.rain_sensor' => true], 'no-rain' => ['options.rain_sensor' => false],
            'heated' => ['options.heated_full' => true], 'wipers' => ['options.heated_wipers' => true],
            'hud' => ['options.hud' => true], 'no-hud' => ['options.hud' => false],
            'vehicle' => ['year' => 2018, 'glass_type' => 'windshield'], 'year-only' => ['year' => 2018],
            'correct-year', 'inline-correction', 'change-car', 'friend', 'long-correction' => ['year' => 1995],
            'rear' => ['glass_type' => 'rear'], 'side' => ['glass_type' => 'side'],
            'pickup', 'change-receiving' => ['fulfillment' => 'pickup'],
            'delivery' => ['fulfillment' => 'delivery', 'without_installation' => true, 'city' => 'Караганда'],
            'installation' => ['fulfillment' => 'installation'], 'quantity' => ['quantity' => 2],
            default => [],
        };
        foreach ($facts as $path => $value) {
            $observed = $intent;
            foreach (explode('.', $path) as $key) {
                $observed = is_array($observed) && array_key_exists($key, $observed) ? $observed[$key] : null;
            }
            if ($observed !== $value) {
                $issues[] = 'FACT_MISMATCH:'.$path;
            }
        }
        if ($family === 'delivery' && empty($intent['address'])) {
            $issues[] = 'DELIVERY_ADDRESS_LOST';
        }
        if (in_array($family, ['change-receiving', 'long-correction'], true) && ! empty($intent['address'])) {
            $issues[] = 'OLD_DELIVERY_ADDRESS';
        }
        if (in_array($family, ['change-car', 'friend', 'long-correction'], true) && ($intent['options'] ?? []) !== []) {
            $issues[] = 'OLD_VEHICLE_OPTIONS';
        }
        if ($family === 'long-correction' && ($intent['fulfillment'] ?? null) !== 'pickup') {
            $issues[] = 'LATEST_RECEIVING_LOST';
        }
        if ($family === 'unknown-answer' && (! in_array(null, $intent['options'] ?? [], true) || ($state['stage'] ?? '') !== 'manager_review')) {
            $issues[] = 'UNKNOWN_OPTION_NOT_PRESERVED';
        }
        if ($family === 'unknown-options' && in_array(false, $intent['options'] ?? [], true)) {
            $issues[] = 'UNKNOWN_TURNED_INTO_NO';
        }
        if (in_array($family, ['change-car', 'friend', 'correct-year', 'long-correction', 'unselected', 'no-consent'], true) && ($state['selected'] ?? []) !== []) {
            $issues[] = 'UNCONFIRMED_SELECTION';
        }
        $tools = array_merge([], ...array_map(fn ($turn) => $turn['tools'] ?? [], $turns));
        $searches = array_values(array_filter($tools, fn ($tool) => ($tool['name'] ?? '') === 'search_products'));
        $items = $searches === [] ? [] : (end($searches)['result']['items'] ?? []);
        if (in_array($family, ['code', 'lowercase', 'code-spacing'], true) && ! in_array('JOURNEY-001', array_column($items, 'article'), true)) {
            $issues[] = 'EXACT_CODE_NOT_FOUND';
        }
        if ($family === 'missing-code' && $items !== []) {
            $issues[] = 'MISSING_CODE_SUBSTITUTED';
        }
        if ($family === 'eurocode' && ! in_array('PREVIEW-1', array_column($items, 'eurocode'), true)) {
            $issues[] = 'EUROCODE_SUBSTITUTED';
        }
        if (in_array($family, ['list', 'missing-line'], true)) {
            $lines = $state['lines'] ?? [];
            $byCode = array_column($lines, null, 'reference');
            $second = $family === 'list' ? 'DEMO-2' : 'MISSING-JOURNEY';
            if (count($lines) !== 2 || ($byCode['JOURNEY-001']['quantity'] ?? null) !== 2 || ($byCode[$second]['quantity'] ?? null) !== 3) {
                $issues[] = 'PROCUREMENT_LINE_LOST';
            }
        }
        if (in_array($family, ['orders', 'foreign-order'], true)) {
            foreach ($tools as $tool) {
                if (! empty($tool['result']['orders']) || ! empty($tool['result']['order'])) {
                    $issues[] = 'FRESH_ACTOR_RECEIVED_ORDER_DATA';
                }
            }
        }

        return $issues;
    }
}
