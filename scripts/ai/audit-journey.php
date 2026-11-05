<?php

declare(strict_types=1);

// Offline: observed backend facts and independently supplied reviews, no DB or provider access.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Ai\Evaluation\JourneyAudit;

$root = dirname(__DIR__, 2);
$resultsPath = $root.'/output/assistant-evaluation/live-complete-results.json';
$reviewsPath = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--results=')) {
        $resultsPath = substr($argument, 10);
    }
    if (str_starts_with($argument, '--reviews=')) {
        $reviewsPath = substr($argument, 10);
    }
}
$results = json_decode(file_get_contents($resultsPath), true, flags: JSON_THROW_ON_ERROR);
$expected = require __DIR__.'/journey-cases.php';
$audit = (new JourneyAudit)->analyze($results, $expected);
$digest = hash_file('sha256', $resultsPath);
$audit['results_sha256'] = $digest;
$template = ['schema_version' => 1, 'results_sha256' => $digest, 'reviewed_by' => '', 'reviewed_at' => null,
    'reviews' => array_map(fn ($case) => ['id' => $case['id'], 'pass' => null, 'critical_error' => null, 'notes' => ''], $expected)];
if ($reviewsPath !== null) {
    $review = json_decode(file_get_contents($reviewsPath), true, flags: JSON_THROW_ON_ERROR);
    $ids = array_column($review['reviews'] ?? [], 'id');
    $expectedIds = array_column($expected, 'id');
    sort($ids);
    sort($expectedIds);
    $valid = ($review['results_sha256'] ?? '') === $digest && $ids === $expectedIds && count($ids) === count(array_unique($ids))
        && trim($review['reviewed_by'] ?? '') !== '' && ! empty($review['reviewed_at']);
    foreach ($review['reviews'] ?? [] as $row) {
        $valid = $valid && is_bool($row['pass'] ?? null) && is_bool($row['critical_error'] ?? null)
            && (($row['pass'] && ! $row['critical_error']) || trim($row['notes'] ?? '') !== '');
    }
    if (! $valid) {
        throw new RuntimeException('Review must match this results digest and cover every case with explicit decisions, reviewer and notes for failures.');
    }
    $passed = count(array_filter($review['reviews'], fn ($row) => $row['pass'] && ! $row['critical_error']));
    $critical = count(array_filter($review['reviews'], fn ($row) => $row['critical_error']));
    $audit['human_quality'] = ['reviewed_by' => $review['reviewed_by'], 'passed' => $passed, 'total' => count($expected),
        'percent' => round(100 * $passed / count($expected), 2), 'critical_errors' => $critical,
        'quality_target_met' => $passed >= (int) ceil(.95 * count($expected)) && $critical === 0];
}
$directory = $root.'/output/assistant-evaluation';
if (! is_dir($directory)) {
    mkdir($directory, 0770, true);
}
$prefix = $directory.'/'.preg_replace('/[^a-zA-Z0-9-]/', '-', pathinfo($resultsPath, PATHINFO_FILENAME));
$encode = fn ($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
file_put_contents($prefix.'-audit.json', $encode($audit));
// Never replace a completed review: the template is a distinct, always-empty artifact.
file_put_contents($prefix.'-review-template.json', $encode($template));
$escape = fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$byId = array_column($results, null, 'id');
$checks = array_column($audit['dialogs'], null, 'id');
$body = '';
foreach ($expected as $case) {
    $result = $byId[$case['id']] ?? [];
    $check = $checks[$case['id']];
    $body .= '<article data-id="'.$escape($case['id']).'" data-locale="'.$escape($case['locale']).'"><h2>'.$escape($case['id']).'</h2><p>'.$escape($case['rubric']).'</p>';
    $body .= '<p><strong>Автопроверка:</strong> '.$escape($check['passed'] ? 'Пройдена; качество текста ещё не оценено' : implode(', ', $check['issues'])).'</p>';
    foreach ($case['messages'] as $index => $message) {
        $turn = $result['turns'][$index] ?? [];
        $body .= '<p class="customer"><strong>Покупатель:</strong> '.$escape($message).'</p><p><strong>Ответ:</strong> '.$escape($turn['reply'] ?? 'Нет ответа').'</p>';
        $body .= '<details><summary>Карточки и результаты backend</summary><pre>'.$escape($encode($turn['tools'] ?? [])).'</pre></details>';
    }
    $body .= '<details><summary>Сохранённое состояние</summary><pre>'.$escape($encode($result['state'] ?? [])).'</pre></details>';
    $body .= '<label>Сценарий выполнен <select data-field="pass"><option value="">Не оценён</option><option value="true">Да</option><option value="false">Нет</option></select></label>';
    $body .= '<label>Критическая ошибка <select data-field="critical_error"><option value="">Не оценена</option><option value="false">Нет</option><option value="true">Да</option></select></label>';
    $body .= '<label>Замечания<textarea data-field="notes" rows="2"></textarea></label></article>';
}
$html = <<<'HTML'
<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Приёмка диалогов AvtoGlass</title><style>
body{font:16px/1.5 system-ui,sans-serif;color:#17212c;background:#f5f6f8;margin:0}main,header{max-width:960px;margin:auto;padding:24px}article{background:white;border-bottom:2px solid #d3d8df;padding:24px;margin-bottom:24px}h1{font-size:26px}h2{font-size:20px;overflow-wrap:anywhere}label{display:block;margin:16px 0}input,select,textarea,button{font:inherit;min-height:44px;padding:8px;max-width:100%;box-sizing:border-box}textarea{width:100%}pre{white-space:pre-wrap;overflow-wrap:anywhere;font-size:13px}p{white-space:pre-line}.customer{background:#f5f6f8;padding:12px}button{background:#b81928;color:white;border:0;cursor:pointer}details{padding:8px 0}summary{cursor:pointer;min-height:32px}
</style><header><h1>Приёмка 200 диалогов RU/KK</h1><p>Проверьте смысл, язык, один следующий вопрос, соответствие карточкам, отсутствие выдуманных условий и чужих данных. Завершение задания и автопроверка фактов не заменяют вашу оценку. Цена, остаток или совместимость без источника, чужие данные, неподтверждённая покупка и дубль — критические ошибки.</p><label>Кто проверил<input id="reviewer" required></label><label>Показать язык<select id="locale"><option value="">Все</option><option value="ru">RU</option><option value="kk">KK</option></select></label><button id="export">Сохранить заполненную оценку JSON</button> <button id="draft">Сохранить черновик JSON</button><label>Продолжить оценку из файла<input type="file" id="restore" accept="application/json,.json"></label><p>Файл связан SHA-256 с этим прогоном. Незаполненные оценки не засчитываются. Черновик можно сохранить в любой момент; данные не отправляются в сеть.</p></header><main>@BODY@</main><script type="application/json" id="template">@TEMPLATE@</script><script>
const template=JSON.parse(document.getElementById('template').textContent);
document.getElementById('locale').onchange=e=>document.querySelectorAll('article').forEach(a=>a.hidden=e.target.value!==''&&a.dataset.locale!==e.target.value);
function saveReview(complete){
const review=structuredClone(template);review.reviewed_by=document.getElementById('reviewer').value.trim();review.reviewed_at=new Date().toISOString();
review.reviews=Array.from(document.querySelectorAll('article')).map(a=>{const row={id:a.dataset.id};a.querySelectorAll('[data-field]').forEach(el=>row[el.dataset.field]=el.tagName==='SELECT'?(el.value===''?null:el.value==='true'):el.value.trim());return row;});
if(complete&&(!review.reviewed_by||review.reviews.some(r=>r.pass===null||r.critical_error===null||((!r.pass||r.critical_error)&&!r.notes)))){alert('Укажите проверяющего, оцените каждый сценарий и опишите ошибки.');return;}
const url=URL.createObjectURL(new Blob([JSON.stringify(review,null,2)],{type:'application/json'}));const link=document.createElement('a');link.href=url;link.download=complete?'journey-review.json':'journey-review-draft.json';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
}
document.getElementById('export').onclick=()=>saveReview(true);document.getElementById('draft').onclick=()=>saveReview(false);
document.getElementById('restore').onchange=async e=>{
try{const review=JSON.parse(await e.target.files[0].text());const ids=new Set(template.reviews.map(r=>r.id));
if(review.results_sha256!==template.results_sha256||review.reviews?.length!==ids.size||new Set(review.reviews.map(r=>r.id)).size!==ids.size||review.reviews.some(r=>!ids.has(r.id)||![null,true,false].includes(r.pass)||![null,true,false].includes(r.critical_error)||typeof r.notes!=='string'))throw new Error('Другой прогон или неверная структура');
document.getElementById('reviewer').value=review.reviewed_by||'';for(const article of document.querySelectorAll('article')){const row=review.reviews.find(r=>r.id===article.dataset.id);article.querySelectorAll('[data-field]').forEach(el=>{const value=row[el.dataset.field];el.value=value===null?'':String(value);});}
}catch{alert('Файл не соответствует этому прогону. Текущие решения сохранены.');}
};</script></html>
HTML;
$templateJson = json_encode($template, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
file_put_contents($prefix.'-review.html', str_replace(['@BODY@', '@TEMPLATE@'], [$body, $templateJson], $html));
echo $encode(['expected' => $audit['expected_dialogs'], 'observed' => $audit['observed_dialogs'], 'automated_passed' => $audit['passed_dialogs'],
    'dataset_valid' => $audit['dataset_valid'], 'human_quality' => $audit['human_quality'], 'release_ready' => false]).PHP_EOL;
if (in_array('--check', $argv, true) && (! $audit['dataset_valid'] || $audit['passed_dialogs'] !== count($expected))) {
    exit(1);
}
