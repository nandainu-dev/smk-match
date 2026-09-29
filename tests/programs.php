<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\Program; use App\Core\ProgramProvider; use App\Core\SchoolProfileProvider;
function programExpect(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$base = (new ProgramProvider())->active(); programExpect(count($base) === 3, 'Initial fixtures missing.');
$four = new ProgramProvider([...$base, new Program('TEST','Synthetic Program','TEST TITLE',null,['primary'=>'#112233'],5,true), new Program('OFF','Inactive','OFF',null,[],1,false)]);
$active = $four->active(); programExpect(count($active) === 4 && $active[0]->code === 'TEST', 'N-program ordering/filtering failed.');
programExpect(SchoolProfileProvider::color('#112233','#000000') === '#112233', 'Safe color failed.'); programExpect(SchoolProfileProvider::color('javascript:bad','#000000') === '#000000', 'Unsafe color accepted.');
programExpect(ProgramProvider::assetPath('/assets/mascots/dkv/dkv-hero.png') !== null, 'Safe mascot rejected.'); programExpect(ProgramProvider::assetPath('data:text/html,x') === null, 'Unsafe asset accepted.');
programExpect(htmlspecialchars('<b>', ENT_QUOTES, 'UTF-8') === '&lt;b&gt;', 'Escaping failed.'); echo "Program tests passed.\n";
