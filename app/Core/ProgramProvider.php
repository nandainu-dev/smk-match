<?php
declare(strict_types=1);
namespace App\Core;
final class ProgramProvider {
    /** @param list<Program>|null $programs */
    public function __construct(private readonly ?array $programs = null) {}
    /** @return list<Program> */
    public function active(): array { $programs = $this->programs ?? $this->developmentDefaults(); $programs = array_values(array_filter($programs, static fn(Program $program): bool => $program->active)); usort($programs, static fn(Program $a, Program $b): int => $a->order <=> $b->order); return $programs; }
    /** @return list<Program> */
    private function developmentDefaults(): array { return [
        new Program('DKV','Desain Komunikasi Visual','THE VISUAL CREATOR','/assets/mascots/dkv/dkv-hero.png',['primary'=>'#7C3AED','accent'=>'#EC4899'],10,true),
        new Program('MPLB','Manajemen Perkantoran dan Layanan Bisnis','THE SMART ORGANIZER','/assets/mascots/mplb/mplb-hero.png',['primary'=>'#0EA5E9','accent'=>'#14B8A6'],20,true),
        new Program('PM','Pemasaran','THE MARKET CONNECTOR','/assets/mascots/pm/pm-hero.png',['primary'=>'#F97316','accent'=>'#FACC15'],30,true),
    ]; }
    public static function assetPath(?string $path): ?string { return $path !== null && preg_match('#^/assets/mascots/[a-z0-9/-]+\.png$#', $path) === 1 ? $path : null; }
}
