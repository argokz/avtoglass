<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Evaluation\JourneyAudit;
use PHPUnit\Framework\TestCase;

final class JourneyAuditTest extends TestCase
{
    private function observedCase(string $family, array $intent): array
    {
        return ['id' => 'ru-retail-'.$family, 'locale' => 'ru', 'buyer' => 'retail', 'messages' => ['Explicit customer request'],
            'state' => ['intent' => $intent], 'turns' => [['status' => 'completed', 'reply' => 'Ответ', 'new_orders' => 0,
                'provider_requests' => 0, 'runtime_seconds' => .1, 'tools' => []]]];
    }

    public function test_completed_generation_cannot_hide_the_wrong_camera_fact(): void
    {
        $result = $this->observedCase('camera-correction', ['options' => ['camera' => true]]);
        $audit = (new JourneyAudit)->analyze([$result], [$result]);
        $this->assertSame(0, $audit['passed_dialogs']);
        $this->assertContains('FACT_MISMATCH:options.camera', $audit['dialogs'][0]['issues']);
        $result['state']['intent']['options']['camera'] = false;
        $audit = (new JourneyAudit)->analyze([$result], [$result]);
        $this->assertSame(1, $audit['passed_dialogs']);
        $this->assertSame('not_scored', $audit['human_quality']);
        $this->assertFalse($audit['release_ready']);
    }

    public function test_unknown_is_not_an_absent_option_or_a_negative_answer(): void
    {
        $result = $this->observedCase('unknown-answer', ['options' => ['camera' => false]]);
        $result['state']['stage'] = 'manager_review';
        $audit = (new JourneyAudit)->analyze([$result], [$result]);
        $this->assertSame(0, $audit['passed_dialogs']);
        $result['state']['intent']['options']['camera'] = null;
        $this->assertSame(1, (new JourneyAudit)->analyze([$result], [$result])['passed_dialogs']);
    }

    public function test_missing_duplicate_or_changed_cases_cannot_pass_dataset_validation(): void
    {
        $result = $this->observedCase('no-camera', ['options' => ['camera' => false]]);
        $expected = [$result, $this->observedCase('camera', ['options' => ['camera' => true]])];
        $audit = (new JourneyAudit)->analyze([$result, $result], $expected);
        $this->assertFalse($audit['dataset_valid']);
        $this->assertSame([$result['id']], $audit['duplicate_ids']);
        $this->assertContains('RESULT_MISSING', $audit['dialogs'][1]['issues']);
        $changed = $result;
        $changed['messages'] = ['Different request'];
        $this->assertContains('CASE_INPUT_CHANGED', (new JourneyAudit)->analyze([$changed], [$result])['dialogs'][0]['issues']);
    }

    public function test_purchase_and_physical_request_limits_are_checked_even_when_the_job_completed(): void
    {
        $result = $this->observedCase('no-camera', ['options' => ['camera' => false]]);
        $expected = $result;
        $result['turns'][0]['new_orders'] = 1;
        $result['turns'][0]['provider_requests'] = 3;
        $result['turns'][0]['runtime_seconds'] = 31;
        $issues = (new JourneyAudit)->analyze([$result], [$expected])['dialogs'][0]['issues'];
        $this->assertContains('UNCONFIRMED_ORDER_OR_MISSING_EVIDENCE', $issues);
        $this->assertContains('PHYSICAL_REQUEST_BUDGET', $issues);
        $this->assertContains('TURN_DEADLINE', $issues);
    }
}
