<?php
/** Isolated input/ownership-facing behavior; does not pretend to test Postgres/HTTP. */
declare(strict_types=1);

$base=dirname(__DIR__);
foreach (['AnsiAvatar','Cp437','AnsiAvatarPayload','AnsiAvatarTerminal'] as $name) {
    require $base . '/src/Avatar/' . $name . '.php';
}
use BinktermPHP\Avatar\AnsiAvatar;
use BinktermPHP\Avatar\AnsiAvatarPayload;
use BinktermPHP\Avatar\AnsiAvatarTerminal;

$checks = 0;
function assertOk(bool $yes,string $label): void {
    global $checks;
    ++$checks;
    if (!$yes) throw new RuntimeException($label);
}
function fails(callable $fn,string $label): void {
    global $checks;
    ++$checks;
    try { $fn(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException("Unexpected acceptance: " . $label);
}
$bin=str_repeat(" \x07",60);
$bin[0]=chr(0x1B);  // CP437 glyph 0x1B, not an executable escape
$bin[2]=chr(0xDB);  // CP437 full block
$avatar=AnsiAvatar::fromBin($bin);
$code=$avatar->toBase64();
assertOk(AnsiAvatarPayload::parseBody(json_encode(['ansi'=>$code]))->equals($avatar), 'Payload accepted');
fails(fn()=>AnsiAvatarPayload::parseBody('{"ansi":"AAAA"}'),'Short payload rejected');
fails(fn()=>AnsiAvatarPayload::parseBody(str_repeat(' ',1100)),'Huge payload rejected');
fails(fn()=>AnsiAvatarPayload::parseBody('{"not_avatar":1}'),'Missing avatar rejected');
assertOk(!AnsiAvatarPayload::csrfMatches('', 'anything'),'Empty CSRF rejected');
assertOk(!AnsiAvatarPayload::csrfMatches(str_repeat('A',32),str_repeat('B',32)), 'Wrong CSRF rejected');
assertOk(!AnsiAvatarPayload::csrfMatches(str_repeat('A',32),null), 'Absent CSRF rejected');
assertOk(AnsiAvatarPayload::csrfMatches(str_repeat('A',32),str_repeat('A',32)), 'Matching CSRF accepted');
$projection=AnsiAvatarPayload::present($avatar);
assertOk(count($projection['cells'])===6 && count($projection['cells'][0])===10,'JSON shape six by ten');
assertOk($projection['cells'][0][0]['glyph']==='←','Escape byte converted to inert visible glyph');
assertOk($projection['ansi']===$code,'Representation retains exact bytes');
$cp437=AnsiAvatarTerminal::lines($avatar,true,'cp437');
assertOk(strlen($cp437[0])>10 && str_contains($cp437[0],chr(0xDB)),'CP437 block remains one byte');
assertOk(!str_contains($cp437[0],"\x1b[0;1;"),'No unexpected SGR pattern');
assertOk(substr_count($cp437[0],"\x1b[")===11,'Exactly ten generated SGR plus reset');
assertOk(str_contains($cp437[0],'<'),'Raw ESC substitution is printable');
assertOk(AnsiAvatarTerminal::lines($avatar,false,'cp437')===null,'No-color fallback');
echo "PASS: {$checks} ANSI avatar integration checks\n";
