<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\ProgramProvider;
use App\Core\QuizDefinition;
use App\Core\QuizProvider;
function assertQuiz(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function expectInvalid(callable $callback, string $case): void { try { $callback(); } catch (InvalidArgumentException) { return; } throw new RuntimeException("Expected invalid: {$case}"); }
$quiz = (new QuizProvider(new ProgramProvider()))->development();
$quiz->validate();
assertQuiz(count($quiz->questions) === 5, 'Expected five questions.');
assertQuiz(count(array_unique(array_column($quiz->questions, 'text'))) === 5, 'Prompts must differ.');
$signatures = [1 => [], 2 => [], 3 => [], 4 => []];
$hasMultiProgramOption = false;
foreach ($quiz->questions as $question) {
    assertQuiz($question['id'] !== '' && $question['text'] !== '' && is_int($question['order']), 'Question shape.');
    assertQuiz(count($question['options']) === 4, 'Expected four options.');
    foreach ($question['options'] as $option) {
        if (count($option['weights']) > 1) $hasMultiProgramOption = true;
        assertQuiz($option['id'] !== '' && $option['text'] !== '' && is_int($option['order']), 'Option shape.');
        assertQuiz(!preg_match('/dkv|mplb|pm|the visual creator|the smart organizer|the market connector/i', $question['text'] . ' ' . $option['text']), 'Neutral content.');
        foreach ($option['weights'] as $weight) assertQuiz(isset($weight['program'], $weight['weight']), 'Relational weight row.');
        $pairs = array_map(static fn(array $w): string => $w['program'] . ':' . $w['weight'], $option['weights']); sort($pairs);
        $signatures[$option['order']][] = implode('|', $pairs);
    }
}
assertQuiz($hasMultiProgramOption, 'At least one development option must weight multiple programs.');
foreach ($signatures as $items) assertQuiz(count(array_unique($items)) > 1, 'Position signature must vary.');
$orderedQuiz = new QuizDefinition('x', 1, ['TEST' => true], [['id'=>'q-later','text'=>'Later question','order'=>20,'options'=>[['id'=>'later-a','text'=>'A','order'=>30,'weights'=>[['program'=>'TEST','weight'=>1]]],['id'=>'later-b','text'=>'B','order'=>10,'weights'=>[]]]],['id'=>'q-earlier','text'=>'Earlier question','order'=>10,'options'=>[['id'=>'early-a','text'=>'A','order'=>1,'weights'=>[]],['id'=>'early-b','text'=>'B','order'=>2,'weights'=>[]]]]]);
$orderedQuiz->validate(); assertQuiz($orderedQuiz->questions[0]['order'] === 20, 'Later order retained.'); assertQuiz($orderedQuiz->questions[1]['order'] === 10, 'Earlier order retained.');
foreach ([['UNKNOWN', 1], ['TEST', 1], ['DKV', 'bad'], ['DKV', NAN], ['DKV', INF], ['', 1], ['   ', 1]] as [$program, $weight]) {
    expectInvalid(static function () use ($program, $weight): void { (new QuizDefinition('x', 1, ['DKV'=>true], [['id'=>'q','text'=>'q','order'=>1,'options'=>[['id'=>'a','text'=>'a','order'=>1,'weights'=>[['program'=>$program,'weight'=>$weight]]],['id'=>'b','text'=>'b','order'=>2,'weights'=>[]]]]]))->validate(); }, 'weight/program');
}
expectInvalid(static function (): void { (new QuizDefinition('x', 1, ['DKV'=>true], [['id'=>'q','text'=>'q','order'=>1,'options'=>[['id'=>'a','text'=>'a','order'=>1,'weights'=>[['program'=>'DKV','weight'=>1],['program'=>'DKV','weight'=>2]]],['id'=>'b','text'=>'b','order'=>2,'weights'=>[]]]]]))->validate(); }, 'duplicate');
expectInvalid(static function (): void { (new QuizDefinition('x', 1, ['DKV'=>true], [['id'=>'q','text'=>'q','order'=>1,'options'=>[['id'=>'a','text'=>'a','order'=>1,'weights'=>[['program'=>'DKV']]],['id'=>'b','text'=>'b','order'=>2,'weights'=>[]]]]]))->validate(); }, 'missing weight');
foreach ([['',1,[]],['   ',1,[]],['x',0,[]],['x',-1,[]]] as [$name,$version,$questions]) expectInvalid(static function()use($name,$version,$questions):void{(new QuizDefinition($name,$version,['DKV'=>true],$questions))->validate();},'quiz');
$base=['id'=>'q','text'=>'q','order'=>1,'options'=>[['id'=>'a','text'=>'a','order'=>1,'weights'=>[]],['id'=>'b','text'=>'b','order'=>2,'weights'=>[]]]];foreach(['id'=>'','text'=>''] as $key=>$value){$bad=$base;$bad[$key]=$value;expectInvalid(static function()use($bad):void{(new QuizDefinition('x',1,[],[$bad]))->validate();},'question');}$bad=$base;$bad['options']=array_slice($bad['options'],0,1);expectInvalid(static function()use($bad):void{(new QuizDefinition('x',1,[],[$bad]))->validate();},'minimum');
foreach(['id','text'] as $key){$bad=$base;$bad['options'][0][$key]='';expectInvalid(static function()use($bad):void{(new QuizDefinition('x',1,[],[$bad]))->validate();},'option');}
foreach ([[['id'=>'q','text'=>'q','order'=>1],['id'=>'q','text'=>'r','order'=>2]], [['id'=>'q','text'=>'q','order'=>1],['id'=>'r','text'=>'r','order'=>1]]] as $questions) expectInvalid(static function () use ($questions): void { foreach ($questions as &$q) $q['options']=[['id'=>'a','text'=>'a','order'=>1,'weights'=>[]],['id'=>'b','text'=>'b','order'=>2,'weights'=>[]]]; (new QuizDefinition('x',1,[],$questions))->validate(); }, 'duplicate question');
foreach ([[['id'=>'a','text'=>'a','order'=>1],['id'=>'a','text'=>'b','order'=>2]], [['id'=>'a','text'=>'a','order'=>1],['id'=>'b','text'=>'b','order'=>1]]] as $options) expectInvalid(static function () use ($options): void { (new QuizDefinition('x',1,[],[['id'=>'q','text'=>'q','order'=>1,'options'=>$options]]))->validate(); }, 'duplicate option');
expectInvalid(static function (): void { (new QuizDefinition('Valid Quiz', 1, ['DKV'=>true], []))->validate(); }, 'zero questions');
foreach ([['id', '   '], ['text', '   ']] as [$field, $value]) { $invalid = $base; $invalid[$field] = $value; expectInvalid(static function () use ($invalid): void { (new QuizDefinition('x', 1, [], [$invalid]))->validate(); }, 'whitespace question'); }
foreach ([['id', '   '], ['text', '   ']] as [$field, $value]) { $invalid = $base; $invalid['options'][0][$field] = $value; expectInvalid(static function () use ($invalid): void { (new QuizDefinition('x', 1, [], [$invalid]))->validate(); }, 'whitespace option'); }
echo "Quiz tests passed.\n";
