<?php
/** Portable regression tests requiring PHP >=8.2 only; no database or Composer. */
declare(strict_types=1);

$base=dirname(__DIR__);
foreach (['AnsiAvatar','Cp437','AnsiAvatarTerminal','AnsiAvatarWeb'] as $name) {
    require $base . '/src/Avatar/' . $name . '.php';
}
use BinktermPHP\Avatar\AnsiAvatar;
use BinktermPHP\Avatar\Cp437;
use BinktermPHP\Avatar\AnsiAvatarTerminal;
use BinktermPHP\Avatar\AnsiAvatarWeb;

$checks=0;
function ok(bool $condition,string $description): void {global $checks; $checks++; if(!$condition)throw new RuntimeException('FAIL: '.$description);}
function rejects(callable $f,string $description): void {
    global $checks; $checks++;
    try{$f();}catch(InvalidArgumentException){return;}
    throw new RuntimeException('FAIL (did not reject): '.$description);
}

$rows=array_fill(0,6,array_fill(0,10,[ord('A'),14,1]));
$rows[0][0]=[0x1B,14,1];
$rows[0][1]=[0xDB,15,0];
$rows[0][2]=[0x7F,7,0];
$rows[0][3]=[ord('<'),3,0];
$rows[0][4]=[ord('&'),4,0];
$face=AnsiAvatar::fromCells($rows);
ok(strlen($face->toBin())===120,'120-byte BIN');
ok(strlen($face->toBase64())===160,'160-char canonical Base64');
ok($face->equals(AnsiAvatar::fromBase64($face->toBase64())),'BIN/base64 roundtrip');
ok(count($face->cells())===6 && count($face->cells()[0])===10,'6x10 cells');
ok($face->cells()[0][0]['char']===0x1B,'CP437 ESC kept inert');
ok($face->cells()[0][1]['fg']===15,'bright fg preserved');
$blinking=substr_replace($face->toBin(),chr(0x80|0x1E),1,1);
ok(AnsiAvatar::fromBin($blinking)->cells()[0][0]['blink']===true,'blink stored');
rejects(fn()=>AnsiAvatar::fromBin(str_repeat('X',119)),'short BIN');
rejects(fn()=>AnsiAvatar::fromBin(str_repeat('X',121)),'long BIN');
rejects(fn()=>AnsiAvatar::fromBin(str_repeat(" \x07",60)),'blank on black');
ok(strlen(AnsiAvatar::fromBin(str_repeat(" \x17",60))->toBin())===120,'colored blanks allowed');
rejects(fn()=>AnsiAvatar::fromBase64($face->toBase64()."\n"),'newline in Base64');
rejects(fn()=>AnsiAvatar::fromBase64(substr($face->toBase64(),0,159).'='),'noncanonical Base64');
rejects(fn()=>AnsiAvatar::fromCells(array_fill(0,5,$rows[0])),'five rows');
rejects(fn()=>AnsiAvatar::fromCells(array_fill(0,6,array_fill(0,10,[65,16,0]))),'foreground out of range');
rejects(fn()=>AnsiAvatar::fromCells(array_fill(0,6,array_fill(0,10,[65,15,8]))),'background out of range');
ok(count(Cp437::glyphs())===256,'CP437 mapping size');
ok(Cp437::glyph(0x1B)==='←' && Cp437::glyph(0x7F)==='⌂','CP437 display glyphs');
$utf8=AnsiAvatarTerminal::lines($face,true,'utf8');
ok(count($utf8)===6 && str_contains($utf8[0],'←'),'UTF8 glyph not command');
ok(substr_count($utf8[0],"\x1b[")===11,'ten synthesized SGR + one reset');
ok(!str_contains(implode('', $utf8),"\x1b[5m"),'no blink SGR');
$cp=AnsiAvatarTerminal::lines($face,true,'cp437');
ok(str_contains($cp[0],'<'),'unsafe CP437 ESC transliterated');
$ascii=AnsiAvatarTerminal::lines($face,true,'ascii');
ok(!str_contains($ascii[0],'←'),'ASCII fallback');
ok(AnsiAvatarTerminal::lines($face,false)===null,'monochrome falls back to handle');
rejects(fn()=>AnsiAvatarTerminal::lines($face,true,'unknown'),'unsupported charset rejected');
$html=AnsiAvatarWeb::render($face,'<A & B>',true);
ok(str_contains($html,'&lt;A &amp; B&gt;'),'owner HTML escaped');
ok(substr_count($html,'bt-ansi-cell bt-ansi-fg-')===60,'sixty inert cells');
ok(!str_contains($html,"\x1b"),'no escape bytes in Web HTML');
ok(str_contains($html,'bt-ansi-shape-full'),'block geometry');
ok(str_contains($html,'&lt;') && str_contains($html,'&amp;'),'glyph markup escaped');
print "PASS: $checks ANSI avatar core checks\n";
