<?php

namespace BinktermPHP;

/**
 * Minimal RIPscrip renderer for the common geometry-and-text subset used in menus.
 *
 * Supported commands:
 * - cNN: set current color index
 * - Lxxxx....: draw lines using base-36 encoded coordinates
 * - Rxxxx....: draw an outline rectangle using two base-36 encoded corners
 * - Bxxxx....: draw a filled rectangle using two base-36 encoded corners
 * - Cxxxxxx: draw an outline circle from center/radius coordinates
 * - Gxxxxxx: draw a filled circle from center/radius coordinates
 * - Oxxxxxxxx: draw an outline ellipse from bounding corners
 * - Oxxxxxxxxxxxx / Vxxxxxxxxxxxx: draw an elliptical arc from center, angles, and radii
 * - oxxxxxxxx: draw a filled ellipse from center and radii
 * - Exxxxxxxx: draw a filled ellipse from bounding corners
 * - P...: draw a polygon outline from a coordinate list
 * - F... / p...: draw a filled polygon from a coordinate list
 * - @xxxxText: place text at a base-36 encoded coordinate
 *
 * Unsupported commands are ignored so callers still get partial output.
 */
class RipScriptRenderer
{
    private const DEFAULT_WIDTH = 640;
    private const DEFAULT_HEIGHT = 350;
    private const ANSI_COLS = 80;
    private const ANSI_ROWS = 25;

    private const HTML_WRAPPER_STYLE = 'margin:0;padding:0.75rem 1rem;background:#000;'
        . 'border:1px solid #193247;border-radius:6px;overflow:auto;';

    /** @var array<int, string> */
    private const RIP_PALETTE = [
        0 => '#000000',
        1 => '#1f35ff',
        2 => '#20c020',
        3 => '#00d7d7',
        4 => '#c02020',
        5 => '#c020c0',
        6 => '#b08020',
        7 => '#c0c0c0',
        8 => '#606060',
        9 => '#00ffff',
        10 => '#ff4040',
        11 => '#ff40ff',
        12 => '#40ff40',
        13 => '#ffff40',
        14 => '#8e8e8e',
        15 => '#d8d8ff',
    ];

    /** @var array<int, string> */
    private const BOX_CHARS = [
        0 => ' ',
        1 => '│',
        2 => '─',
        3 => '└',
        4 => '│',
        5 => '│',
        6 => '┌',
        7 => '├',
        8 => '─',
        9 => '┘',
        10 => '─',
        11 => '┴',
        12 => '┐',
        13 => '┤',
        14 => '┬',
        15 => '┼',
    ];

    private string $script;

    /** @var array{width:int,height:int,lines:array<int,array<string,mixed>>,rects:array<int,array<string,mixed>>,fills:array<int,array<string,mixed>>,circles:array<int,array<string,mixed>>,filledCircles:array<int,array<string,mixed>>,ellipses:array<int,array<string,mixed>>,filledEllipses:array<int,array<string,mixed>>,ellipseArcs:array<int,array<string,mixed>>,polygons:array<int,array<string,mixed>>,filledPolygons:array<int,array<string,mixed>>,texts:array<int,array<string,mixed>>}|null */
    private ?array $scene = null;

    public function __construct(string $script)
    {
        $this->script = $this->normalizeLineEndings($script);
    }

    public static function fromString(string $script): self
    {
        return new self($script);
    }

    public function getHTML(): string
    {
        $scene = $this->getScene();
        $svg = [];
        $svg[] = '<svg viewBox="0 0 ' . $scene['width'] . ' ' . $scene['height'] . '"'
            . ' xmlns="http://www.w3.org/2000/svg" role="img" aria-label="RIPscrip render"'
            . ' style="display:block;width:100%;height:auto;background:#000;font-family:Consolas, \'Courier New\', monospace;">';
        $svg[] = '<rect width="100%" height="100%" fill="#000"/>';

        foreach ($scene['lines'] as $line) {
            $svg[] = '<line x1="' . $line['x1'] . '" y1="' . $line['y1']
                . '" x2="' . $line['x2'] . '" y2="' . $line['y2']
                . '" stroke="' . $line['color'] . '" stroke-width="1" shape-rendering="crispEdges"/>';
        }

        foreach ($scene['fills'] as $fill) {
            $x = min($fill['x1'], $fill['x2']);
            $y = min($fill['y1'], $fill['y2']);
            $width = max(1, abs($fill['x2'] - $fill['x1']));
            $height = max(1, abs($fill['y2'] - $fill['y1']));
            $svg[] = '<rect x="' . $x . '" y="' . $y
                . '" width="' . $width . '" height="' . $height
                . '" fill="' . $fill['color'] . '" shape-rendering="crispEdges"/>';
        }

        foreach ($scene['rects'] as $rect) {
            $x = min($rect['x1'], $rect['x2']);
            $y = min($rect['y1'], $rect['y2']);
            $width = max(1, abs($rect['x2'] - $rect['x1']));
            $height = max(1, abs($rect['y2'] - $rect['y1']));
            $svg[] = '<rect x="' . $x . '" y="' . $y
                . '" width="' . $width . '" height="' . $height
                . '" fill="none" stroke="' . $rect['color']
                . '" stroke-width="1" shape-rendering="crispEdges"/>';
        }

        foreach ($scene['circles'] as $circle) {
            $svg[] = '<circle cx="' . $circle['cx'] . '" cy="' . $circle['cy']
                . '" r="' . $circle['radius']
                . '" fill="none" stroke="' . $circle['color']
                . '" stroke-width="1" shape-rendering="crispEdges"/>';
        }

        foreach ($scene['filledCircles'] as $circle) {
            $svg[] = '<circle cx="' . $circle['cx'] . '" cy="' . $circle['cy']
                . '" r="' . $circle['radius']
                . '" fill="' . $circle['color']
                . '" stroke="none" shape-rendering="crispEdges"/>';
        }

        foreach ($scene['ellipses'] as $ellipse) {
            $left = min($ellipse['x1'], $ellipse['x2']);
            $right = max($ellipse['x1'], $ellipse['x2']);
            $top = min($ellipse['y1'], $ellipse['y2']);
            $bottom = max($ellipse['y1'], $ellipse['y2']);
            $svg[] = '<ellipse cx="' . (($left + $right) / 2) . '" cy="' . (($top + $bottom) / 2)
                . '" rx="' . max(1, ($right - $left) / 2) . '" ry="' . max(1, ($bottom - $top) / 2)
                . '" fill="none" stroke="' . $ellipse['color']
                . '" stroke-width="1" shape-rendering="crispEdges"/>';
        }

        foreach ($scene['filledEllipses'] as $ellipse) {
            $left = min($ellipse['x1'], $ellipse['x2']);
            $right = max($ellipse['x1'], $ellipse['x2']);
            $top = min($ellipse['y1'], $ellipse['y2']);
            $bottom = max($ellipse['y1'], $ellipse['y2']);
            $svg[] = '<ellipse cx="' . (($left + $right) / 2) . '" cy="' . (($top + $bottom) / 2)
                . '" rx="' . max(1, ($right - $left) / 2) . '" ry="' . max(1, ($bottom - $top) / 2)
                . '" fill="' . $ellipse['color'] . '" stroke="none" shape-rendering="crispEdges"/>';
        }

        foreach ($scene['ellipseArcs'] as $arc) {
            $svg[] = '<path d="' . $this->svgArcPath(
                $arc['cx'],
                $arc['cy'],
                $arc['rx'],
                $arc['ry'],
                $arc['start'],
                $arc['end']
            ) . '" fill="none" stroke="' . $arc['color']
                . '" stroke-width="1" shape-rendering="crispEdges"/>';
        }

        foreach ($scene['polygons'] as $polygon) {
            $svg[] = '<polygon points="' . $this->svgPointString($polygon['points'])
                . '" fill="none" stroke="' . $polygon['color']
                . '" stroke-width="1" shape-rendering="crispEdges"/>';
        }

        foreach ($scene['filledPolygons'] as $polygon) {
            $svg[] = '<polygon points="' . $this->svgPointString($polygon['points'])
                . '" fill="' . $polygon['color']
                . '" stroke="none" shape-rendering="crispEdges"/>';
        }

        foreach ($scene['texts'] as $text) {
            $y = $text['y'];
            foreach (explode("\n", $text['text']) as $line) {
                $svg[] = '<text x="' . $text['x'] . '" y="' . $y
                    . '" fill="' . $text['color']
                    . '" font-size="14" xml:space="preserve">'
                    . htmlspecialchars($line, ENT_QUOTES, 'UTF-8')
                    . '</text>';
                $y += 16;
            }
        }

        $svg[] = '</svg>';

        return '<div class="rip-script-renderer" style="' . self::HTML_WRAPPER_STYLE . '">'
            . implode('', $svg)
            . '</div>';
    }

    public function getAnsi(): string
    {
        $scene = $this->getScene();
        $grid = $this->buildAnsiGrid($scene);
        $output = [];

        for ($row = 0; $row < self::ANSI_ROWS; $row++) {
            $line = '';
            $activeColor = null;

            for ($col = 0; $col < self::ANSI_COLS; $col++) {
                $cell = $grid[$row][$col];
                if ($cell['color'] !== $activeColor) {
                    $line .= $this->ansiColor($cell['color']);
                    $activeColor = $cell['color'];
                }
                $line .= $cell['char'];
            }

            $output[] = rtrim($line, ' ');
        }

        return implode(PHP_EOL, $output) . "\033[0m";
    }

    public function getPlainText(): string
    {
        return $this->script;
    }

    private function normalizeLineEndings(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * @return array<int, array{opcode:string,payload:string}>
     */
    private function tokenizeRipLine(string $line): array
    {
        $tokens = [];
        $body = '|' . substr($line, 2);
        $length = strlen($body);
        $i = 0;

        while ($i < $length) {
            if ($body[$i] !== '|') {
                $i++;
                continue;
            }

            $i++;
            if ($i >= $length) {
                break;
            }

            $opcode = $body[$i];
            $i++;

            if ($opcode === '@') {
                $payload = '';
                while ($i < $length) {
                    if ($body[$i] === '\\' && ($i + 1) < $length && $body[$i + 1] === '|') {
                        $payload .= '|';
                        $i += 2;
                        continue;
                    }

                    if ($body[$i] === '|' && ($i + 1) < $length && $body[$i + 1] === '|') {
                        $payload .= '|';
                        $i += 2;
                        continue;
                    }

                    if ($body[$i] === '|' && $this->looksLikeRipOpcodeStart(substr($body, $i + 1))) {
                        break;
                    }

                    $payload .= $body[$i];
                    $i++;
                }

                $tokens[] = ['opcode' => $opcode, 'payload' => $payload];
                continue;
            }

            $payload = '';
            while ($i < $length && $body[$i] !== '|') {
                $payload .= $body[$i];
                $i++;
            }

            $tokens[] = ['opcode' => $opcode, 'payload' => $payload];
        }

        return $tokens;
    }

    private function looksLikeRipOpcodeStart(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        return preg_match('/^(?:c\d{2}|[LRBCGOPVEFpo@])/', $text) === 1;
    }

    /**
     * @return array{width:int,height:int,lines:array<int,array<string,mixed>>,rects:array<int,array<string,mixed>>,fills:array<int,array<string,mixed>>,circles:array<int,array<string,mixed>>,filledCircles:array<int,array<string,mixed>>,ellipses:array<int,array<string,mixed>>,filledEllipses:array<int,array<string,mixed>>,ellipseArcs:array<int,array<string,mixed>>,polygons:array<int,array<string,mixed>>,filledPolygons:array<int,array<string,mixed>>,texts:array<int,array<string,mixed>>}
     */
    private function getScene(): array
    {
        if ($this->scene !== null) {
            return $this->scene;
        }

        $scene = [
            'width' => self::DEFAULT_WIDTH,
            'height' => self::DEFAULT_HEIGHT,
            'lines' => [],
            'rects' => [],
            'fills' => [],
            'circles' => [],
            'filledCircles' => [],
            'ellipses' => [],
            'filledEllipses' => [],
            'ellipseArcs' => [],
            'polygons' => [],
            'filledPolygons' => [],
            'texts' => [],
        ];

        $currentColor = 7;
        foreach (explode("\n", $this->script) as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, '!|')) {
                continue;
            }

            foreach ($this->tokenizeRipLine($line) as $token) {
                $opcode = $token['opcode'];
                $payload = $token['payload'];

                if ($opcode === 'c') {
                    if (preg_match('/^\d{2}$/', $payload) === 1) {
                        $currentColor = (int)$payload;
                    }
                    continue;
                }

                if ($opcode === 'L' && strlen($payload) >= 8) {
                    [$x1, $y1, $x2, $y2] = $this->decodeRipRectPayload($payload);
                    $scene['lines'][] = [
                        'x1' => $x1,
                        'y1' => $y1,
                        'x2' => $x2,
                        'y2' => $y2,
                        'color' => $this->colorForIndex($currentColor),
                        'color_index' => $currentColor,
                    ];
                    continue;
                }

                if ($opcode === 'R' && strlen($payload) >= 8) {
                    [$x1, $y1, $x2, $y2] = $this->decodeRipRectPayload($payload);
                    $scene['rects'][] = [
                        'x1' => $x1,
                        'y1' => $y1,
                        'x2' => $x2,
                        'y2' => $y2,
                        'color' => $this->colorForIndex($currentColor),
                        'color_index' => $currentColor,
                    ];
                    continue;
                }

                if ($opcode === 'B' && strlen($payload) >= 8) {
                    [$x1, $y1, $x2, $y2] = $this->decodeRipRectPayload($payload);
                    $scene['fills'][] = [
                        'x1' => $x1,
                        'y1' => $y1,
                        'x2' => $x2,
                        'y2' => $y2,
                        'color' => $this->colorForIndex($currentColor),
                        'color_index' => $currentColor,
                    ];
                    continue;
                }

                if ($opcode === 'C' && strlen($payload) >= 6) {
                    $scene['circles'][] = [
                        'cx' => $this->decodeRipCoord(substr($payload, 0, 2)),
                        'cy' => $this->decodeRipCoord(substr($payload, 2, 2)),
                        'radius' => max(1, $this->decodeRipCoord(substr($payload, 4, 2))),
                        'color' => $this->colorForIndex($currentColor),
                        'color_index' => $currentColor,
                    ];
                    continue;
                }

                if ($opcode === 'G' && strlen($payload) >= 6) {
                    $scene['filledCircles'][] = [
                        'cx' => $this->decodeRipCoord(substr($payload, 0, 2)),
                        'cy' => $this->decodeRipCoord(substr($payload, 2, 2)),
                        'radius' => max(1, $this->decodeRipCoord(substr($payload, 4, 2))),
                        'color' => $this->colorForIndex($currentColor),
                        'color_index' => $currentColor,
                    ];
                    continue;
                }

                if ($opcode === 'O' && strlen($payload) >= 12) {
                    $scene['ellipseArcs'][] = [
                        'cx' => $this->decodeRipCoord(substr($payload, 0, 2)),
                        'cy' => $this->decodeRipCoord(substr($payload, 2, 2)),
                        'start' => $this->decodeRipCoord(substr($payload, 4, 2)),
                        'end' => $this->decodeRipCoord(substr($payload, 6, 2)),
                        'rx' => max(1, $this->decodeRipCoord(substr($payload, 8, 2))),
                        'ry' => max(1, $this->decodeRipCoord(substr($payload, 10, 2))),
                        'color' => $this->colorForIndex($currentColor),
                        'color_index' => $currentColor,
                    ];
                    continue;
                }

                if (($opcode === 'O' || $opcode === 'V') && strlen($payload) >= 8) {
                    [$x1, $y1, $x2, $y2] = $this->decodeRipRectPayload($payload);
                    $scene['ellipses'][] = [
                        'x1' => $x1,
                        'y1' => $y1,
                        'x2' => $x2,
                        'y2' => $y2,
                        'color' => $this->colorForIndex($currentColor),
                        'color_index' => $currentColor,
                    ];
                    continue;
                }

                if ($opcode === 'E' && strlen($payload) >= 8) {
                    [$x1, $y1, $x2, $y2] = $this->decodeRipRectPayload($payload);
                    $scene['filledEllipses'][] = [
                        'x1' => $x1,
                        'y1' => $y1,
                        'x2' => $x2,
                        'y2' => $y2,
                        'color' => $this->colorForIndex($currentColor),
                        'color_index' => $currentColor,
                    ];
                    continue;
                }

                if ($opcode === 'o' && strlen($payload) >= 8) {
                    $cx = $this->decodeRipCoord(substr($payload, 0, 2));
                    $cy = $this->decodeRipCoord(substr($payload, 2, 2));
                    $rx = max(1, $this->decodeRipCoord(substr($payload, 4, 2)));
                    $ry = max(1, $this->decodeRipCoord(substr($payload, 6, 2)));
                    $scene['filledEllipses'][] = [
                        'x1' => $cx - $rx,
                        'y1' => $cy - $ry,
                        'x2' => $cx + $rx,
                        'y2' => $cy + $ry,
                        'color' => $this->colorForIndex($currentColor),
                        'color_index' => $currentColor,
                    ];
                    continue;
                }

                if (($opcode === 'P' || $opcode === 'F' || $opcode === 'p') && strlen($payload) >= 8) {
                    $points = $this->decodeRipPolygonPayload($payload);
                    if (count($points) >= 3) {
                        $target = ($opcode === 'F' || $opcode === 'p') ? 'filledPolygons' : 'polygons';
                        $scene[$target][] = [
                            'points' => $points,
                            'color' => $this->colorForIndex($currentColor),
                            'color_index' => $currentColor,
                        ];
                    }
                    continue;
                }

                if ($opcode === '@' && strlen($payload) >= 4) {
                    $scene['texts'][] = [
                        'x' => $this->decodeRipCoord(substr($payload, 0, 2)),
                        'y' => $this->decodeRipCoord(substr($payload, 2, 2)),
                        'text' => $this->decodeRipText(substr($payload, 4)),
                        'color' => $this->colorForIndex($currentColor),
                        'color_index' => $currentColor,
                    ];
                }
            }
        }

        $this->scene = $scene;
        return $scene;
    }

    private function decodeRipCoord(string $value): int
    {
        return intval(base_convert($value, 36, 10));
    }

    /**
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function decodeRipRectPayload(string $payload): array
    {
        return [
            $this->decodeRipCoord(substr($payload, 0, 2)),
            $this->decodeRipCoord(substr($payload, 2, 2)),
            $this->decodeRipCoord(substr($payload, 4, 2)),
            $this->decodeRipCoord(substr($payload, 6, 2)),
        ];
    }

    /**
     * Accept either:
     * - count-prefixed payload: NN + xxyy repeated NN times
     * - raw coordinate payload: xxyy repeated to exhaustion
     *
     * @return array<int, array{x:int,y:int}>
     */
    private function decodeRipPolygonPayload(string $payload): array
    {
        $points = [];
        $start = 0;
        $remaining = strlen($payload);

        if ($remaining >= 2) {
            $declaredCount = $this->decodeRipCoord(substr($payload, 0, 2));
            if ($declaredCount > 0 && $remaining >= 2 + ($declaredCount * 4)) {
                $start = 2;
                $remaining = $declaredCount * 4;
            } elseif ($remaining % 4 !== 0) {
                $remaining -= $remaining % 4;
            }
        }

        for ($i = $start; $i + 3 < ($start + $remaining); $i += 4) {
            $points[] = [
                'x' => $this->decodeRipCoord(substr($payload, $i, 2)),
                'y' => $this->decodeRipCoord(substr($payload, $i + 2, 2)),
            ];
        }

        return $points;
    }

    private function decodeRipText(string $text): string
    {
        $decoded = '';
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($char !== '\\' || ($i + 1) >= $length) {
                $decoded .= $char;
                continue;
            }

            $next = $text[$i + 1];
            switch ($next) {
                case 'n':
                    $decoded .= "\n";
                    $i++;
                    break;
                case 'r':
                    $decoded .= "\r";
                    $i++;
                    break;
                case 't':
                    $decoded .= "\t";
                    $i++;
                    break;
                case '\\':
                    $decoded .= '\\';
                    $i++;
                    break;
                case '|':
                    $decoded .= '|';
                    $i++;
                    break;
                default:
                    $decoded .= $char;
                    break;
            }
        }

        return $decoded;
    }

    private function colorForIndex(int $index): string
    {
        return self::RIP_PALETTE[$index] ?? self::RIP_PALETTE[7];
    }

    /**
     * @param array<int, array{x:int,y:int}> $points
     */
    private function svgPointString(array $points): string
    {
        return implode(' ', array_map(
            static fn (array $point): string => $point['x'] . ',' . $point['y'],
            $points
        ));
    }

    private function svgArcPath(int $cx, int $cy, int $rx, int $ry, int $startDeg, int $endDeg): string
    {
        [$startX, $startY] = $this->ellipsePoint($cx, $cy, $rx, $ry, $startDeg);
        [$endX, $endY] = $this->ellipsePoint($cx, $cy, $rx, $ry, $endDeg);

        $sweep = ($endDeg - $startDeg) % 360;
        if ($sweep < 0) {
            $sweep += 360;
        }
        $largeArc = $sweep > 180 ? 1 : 0;

        return 'M ' . $startX . ' ' . $startY
            . ' A ' . $rx . ' ' . $ry . ' 0 ' . $largeArc . ' 1 ' . $endX . ' ' . $endY;
    }

    /**
     * @return array{0:float,1:float}
     */
    private function ellipsePoint(float $cx, float $cy, float $rx, float $ry, float $degrees): array
    {
        $theta = deg2rad($degrees);
        return [
            $cx + ($rx * cos($theta)),
            $cy + ($ry * sin($theta)),
        ];
    }

    private function ansiColor(?int $index): string
    {
        $hex = ltrim($this->colorForIndex($index ?? 7), '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "\033[38;2;{$r};{$g};{$b}m";
    }

    /**
     * @param array{width:int,height:int,lines:array<int,array<string,mixed>>,rects:array<int,array<string,mixed>>,fills:array<int,array<string,mixed>>,circles:array<int,array<string,mixed>>,filledCircles:array<int,array<string,mixed>>,ellipses:array<int,array<string,mixed>>,filledEllipses:array<int,array<string,mixed>>,ellipseArcs:array<int,array<string,mixed>>,polygons:array<int,array<string,mixed>>,filledPolygons:array<int,array<string,mixed>>,texts:array<int,array<string,mixed>>} $scene
     * @return array<int, array<int, array{char:string,color:?int,mask:int}>>
     */
    private function buildAnsiGrid(array $scene): array
    {
        $grid = [];
        for ($row = 0; $row < self::ANSI_ROWS; $row++) {
            $grid[$row] = [];
            for ($col = 0; $col < self::ANSI_COLS; $col++) {
                $grid[$row][$col] = ['char' => ' ', 'color' => 7, 'mask' => 0];
            }
        }

        foreach ($scene['fills'] as $fill) {
            $x1 = $this->scaleX($fill['x1'], $scene['width']);
            $x2 = $this->scaleX($fill['x2'], $scene['width']);
            $y1 = $this->scaleY($fill['y1'], $scene['height']);
            $y2 = $this->scaleY($fill['y2'], $scene['height']);

            $startRow = min($y1, $y2);
            $endRow = max($y1, $y2);
            $startCol = min($x1, $x2);
            $endCol = max($x1, $x2);

            for ($row = $startRow; $row <= $endRow; $row++) {
                for ($col = $startCol; $col <= $endCol; $col++) {
                    if ($row < 0 || $row >= self::ANSI_ROWS || $col < 0 || $col >= self::ANSI_COLS) {
                        continue;
                    }
                    $grid[$row][$col] = [
                        'char' => '█',
                        'color' => $fill['color_index'],
                        'mask' => 0,
                    ];
                }
            }
        }

        foreach ($scene['rects'] as $rect) {
            $left = $this->scaleX(min($rect['x1'], $rect['x2']), $scene['width']);
            $right = $this->scaleX(max($rect['x1'], $rect['x2']), $scene['width']);
            $top = $this->scaleY(min($rect['y1'], $rect['y2']), $scene['height']);
            $bottom = $this->scaleY(max($rect['y1'], $rect['y2']), $scene['height']);

            $this->rasterizeAnsiLine($grid, $left, $top, $right, $top, $rect['color_index']);
            $this->rasterizeAnsiLine($grid, $right, $top, $right, $bottom, $rect['color_index']);
            $this->rasterizeAnsiLine($grid, $right, $bottom, $left, $bottom, $rect['color_index']);
            $this->rasterizeAnsiLine($grid, $left, $bottom, $left, $top, $rect['color_index']);
        }

        foreach ($scene['circles'] as $circle) {
            $cx = $this->scaleX($circle['cx'], $scene['width']);
            $cy = $this->scaleY($circle['cy'], $scene['height']);
            $rx = max(1, (int)round(($circle['radius'] / max(1, $scene['width'] - 1)) * (self::ANSI_COLS - 1)));
            $ry = max(1, (int)round(($circle['radius'] / max(1, $scene['height'] - 1)) * (self::ANSI_ROWS - 1)));
            $this->rasterizeAnsiEllipse($grid, $cx, $cy, $rx, $ry, $circle['color_index']);
        }

        foreach ($scene['filledCircles'] as $circle) {
            $cx = $this->scaleX($circle['cx'], $scene['width']);
            $cy = $this->scaleY($circle['cy'], $scene['height']);
            $rx = max(1, (int)round(($circle['radius'] / max(1, $scene['width'] - 1)) * (self::ANSI_COLS - 1)));
            $ry = max(1, (int)round(($circle['radius'] / max(1, $scene['height'] - 1)) * (self::ANSI_ROWS - 1)));
            $this->fillAnsiEllipse($grid, $cx, $cy, $rx, $ry, $circle['color_index']);
        }

        foreach ($scene['ellipses'] as $ellipse) {
            $left = $this->scaleX(min($ellipse['x1'], $ellipse['x2']), $scene['width']);
            $right = $this->scaleX(max($ellipse['x1'], $ellipse['x2']), $scene['width']);
            $top = $this->scaleY(min($ellipse['y1'], $ellipse['y2']), $scene['height']);
            $bottom = $this->scaleY(max($ellipse['y1'], $ellipse['y2']), $scene['height']);
            $cx = (int)round(($left + $right) / 2);
            $cy = (int)round(($top + $bottom) / 2);
            $rx = max(1, (int)round(abs($right - $left) / 2));
            $ry = max(1, (int)round(abs($bottom - $top) / 2));
            $this->rasterizeAnsiEllipse($grid, $cx, $cy, $rx, $ry, $ellipse['color_index']);
        }

        foreach ($scene['filledEllipses'] as $ellipse) {
            $left = $this->scaleX(min($ellipse['x1'], $ellipse['x2']), $scene['width']);
            $right = $this->scaleX(max($ellipse['x1'], $ellipse['x2']), $scene['width']);
            $top = $this->scaleY(min($ellipse['y1'], $ellipse['y2']), $scene['height']);
            $bottom = $this->scaleY(max($ellipse['y1'], $ellipse['y2']), $scene['height']);
            $cx = (int)round(($left + $right) / 2);
            $cy = (int)round(($top + $bottom) / 2);
            $rx = max(1, (int)round(abs($right - $left) / 2));
            $ry = max(1, (int)round(abs($bottom - $top) / 2));
            $this->fillAnsiEllipse($grid, $cx, $cy, $rx, $ry, $ellipse['color_index']);
        }

        foreach ($scene['ellipseArcs'] as $arc) {
            $cx = $this->scaleX($arc['cx'], $scene['width']);
            $cy = $this->scaleY($arc['cy'], $scene['height']);
            $rx = max(1, (int)round(($arc['rx'] / max(1, $scene['width'] - 1)) * (self::ANSI_COLS - 1)));
            $ry = max(1, (int)round(($arc['ry'] / max(1, $scene['height'] - 1)) * (self::ANSI_ROWS - 1)));
            $this->rasterizeAnsiArc($grid, $cx, $cy, $rx, $ry, $arc['start'], $arc['end'], $arc['color_index']);
        }

        foreach ($scene['polygons'] as $polygon) {
            $scaled = $this->scalePolygonPoints($polygon['points'], $scene['width'], $scene['height']);
            $this->rasterizeAnsiPolygonOutline($grid, $scaled, $polygon['color_index']);
        }

        foreach ($scene['filledPolygons'] as $polygon) {
            $scaled = $this->scalePolygonPoints($polygon['points'], $scene['width'], $scene['height']);
            $this->fillAnsiPolygon($grid, $scaled, $polygon['color_index']);
        }

        foreach ($scene['lines'] as $line) {
            $this->rasterizeAnsiLine(
                $grid,
                $this->scaleX($line['x1'], $scene['width']),
                $this->scaleY($line['y1'], $scene['height']),
                $this->scaleX($line['x2'], $scene['width']),
                $this->scaleY($line['y2'], $scene['height']),
                $line['color_index']
            );
        }

        for ($row = 0; $row < self::ANSI_ROWS; $row++) {
            for ($col = 0; $col < self::ANSI_COLS; $col++) {
                if ($grid[$row][$col]['mask'] !== 0) {
                    $grid[$row][$col]['char'] = self::BOX_CHARS[$grid[$row][$col]['mask']] ?? ' ';
                }
            }
        }

        foreach ($scene['texts'] as $text) {
            $row = $this->scaleY($text['y'], $scene['height']);
            $col = $this->scaleX($text['x'], $scene['width']);
            $chars = preg_split('//u', $text['text'], -1, PREG_SPLIT_NO_EMPTY);
            if ($chars === false) {
                continue;
            }

            foreach ($chars as $offset => $char) {
                $targetCol = $col + $offset;
                if ($targetCol < 0 || $targetCol >= self::ANSI_COLS || $row < 0 || $row >= self::ANSI_ROWS) {
                    continue;
                }
                $grid[$row][$targetCol] = [
                    'char' => $char,
                    'color' => $text['color_index'],
                    'mask' => 0,
                ];
            }
        }

        return $grid;
    }

    /**
     * @param array<int, array<int, array{char:string,color:?int,mask:int}>> $grid
     */
    private function addMask(array &$grid, int $row, int $col, int $mask): void
    {
        if ($row < 0 || $row >= self::ANSI_ROWS || $col < 0 || $col >= self::ANSI_COLS) {
            return;
        }

        $grid[$row][$col]['mask'] |= $mask;
    }

    /**
     * @param array<int, array<int, array{char:string,color:?int,mask:int}>> $grid
     */
    private function rasterizeAnsiLine(array &$grid, int $x1, int $y1, int $x2, int $y2, int $colorIndex): void
    {
        if ($y1 === $y2) {
            $start = min($x1, $x2);
            $end = max($x1, $x2);
            for ($col = $start; $col < $end; $col++) {
                $this->addMask($grid, $y1, $col, 2);
                $this->addMask($grid, $y1, $col + 1, 8);

                if ($y1 >= 0 && $y1 < self::ANSI_ROWS && $col >= 0 && $col < self::ANSI_COLS) {
                    $grid[$y1][$col]['color'] = $colorIndex;
                }
                if ($y1 >= 0 && $y1 < self::ANSI_ROWS && ($col + 1) >= 0 && ($col + 1) < self::ANSI_COLS) {
                    $grid[$y1][$col + 1]['color'] = $colorIndex;
                }
            }
            return;
        }

        if ($x1 === $x2) {
            $start = min($y1, $y2);
            $end = max($y1, $y2);
            for ($row = $start; $row < $end; $row++) {
                $this->addMask($grid, $row, $x1, 4);
                $this->addMask($grid, $row + 1, $x1, 1);

                if ($row >= 0 && $row < self::ANSI_ROWS && $x1 >= 0 && $x1 < self::ANSI_COLS) {
                    $grid[$row][$x1]['color'] = $colorIndex;
                }
                if (($row + 1) >= 0 && ($row + 1) < self::ANSI_ROWS && $x1 >= 0 && $x1 < self::ANSI_COLS) {
                    $grid[$row + 1][$x1]['color'] = $colorIndex;
                }
            }
            return;
        }

        $dx = abs($x2 - $x1);
        $dy = abs($y2 - $y1);
        $sx = $x1 < $x2 ? 1 : -1;
        $sy = $y1 < $y2 ? 1 : -1;
        $err = $dx - $dy;
        $x = $x1;
        $y = $y1;

        while (true) {
            if ($y >= 0 && $y < self::ANSI_ROWS && $x >= 0 && $x < self::ANSI_COLS) {
                $grid[$y][$x] = [
                    'char' => '•',
                    'color' => $colorIndex,
                    'mask' => 0,
                ];
            }

            if ($x === $x2 && $y === $y2) {
                break;
            }

            $e2 = $err * 2;
            if ($e2 > -$dy) {
                $err -= $dy;
                $x += $sx;
            }
            if ($e2 < $dx) {
                $err += $dx;
                $y += $sy;
            }
        }
    }

    /**
     * @param array<int, array<int, array{char:string,color:?int,mask:int}>> $grid
     */
    private function rasterizeAnsiEllipse(array &$grid, int $cx, int $cy, int $rx, int $ry, int $colorIndex): void
    {
        $steps = max(24, (int)round(2 * M_PI * max($rx, $ry)));

        for ($i = 0; $i < $steps; $i++) {
            $theta = (2 * M_PI * $i) / $steps;
            $x = (int)round($cx + ($rx * cos($theta)));
            $y = (int)round($cy + ($ry * sin($theta)));

            if ($y < 0 || $y >= self::ANSI_ROWS || $x < 0 || $x >= self::ANSI_COLS) {
                continue;
            }

            $grid[$y][$x] = [
                'char' => '•',
                'color' => $colorIndex,
                'mask' => 0,
            ];
        }
    }

    /**
     * @param array<int, array<int, array{char:string,color:?int,mask:int}>> $grid
     */
    private function fillAnsiEllipse(array &$grid, int $cx, int $cy, int $rx, int $ry, int $colorIndex): void
    {
        for ($y = $cy - $ry; $y <= $cy + $ry; $y++) {
            if ($y < 0 || $y >= self::ANSI_ROWS) {
                continue;
            }

            for ($x = $cx - $rx; $x <= $cx + $rx; $x++) {
                if ($x < 0 || $x >= self::ANSI_COLS) {
                    continue;
                }

                $normX = ($x - $cx) / max(1, $rx);
                $normY = ($y - $cy) / max(1, $ry);
                if (($normX * $normX) + ($normY * $normY) <= 1.0) {
                    $grid[$y][$x] = [
                        'char' => '█',
                        'color' => $colorIndex,
                        'mask' => 0,
                    ];
                }
            }
        }
    }

    /**
     * @param array<int, array<int, array{char:string,color:?int,mask:int}>> $grid
     */
    private function rasterizeAnsiArc(array &$grid, int $cx, int $cy, int $rx, int $ry, int $startDeg, int $endDeg, int $colorIndex): void
    {
        $sweep = ($endDeg - $startDeg) % 360;
        if ($sweep < 0) {
            $sweep += 360;
        }
        if ($sweep === 0) {
            $sweep = 360;
        }

        $steps = max(12, (int)round($sweep * max($rx, $ry) / 12));
        for ($i = 0; $i <= $steps; $i++) {
            $degrees = $startDeg + (($sweep * $i) / $steps);
            $theta = deg2rad($degrees);
            $x = (int)round($cx + ($rx * cos($theta)));
            $y = (int)round($cy + ($ry * sin($theta)));

            if ($y < 0 || $y >= self::ANSI_ROWS || $x < 0 || $x >= self::ANSI_COLS) {
                continue;
            }

            $grid[$y][$x] = [
                'char' => '•',
                'color' => $colorIndex,
                'mask' => 0,
            ];
        }
    }

    /**
     * @param array<int, array{x:int,y:int}> $points
     * @return array<int, array{x:int,y:int}>
     */
    private function scalePolygonPoints(array $points, int $width, int $height): array
    {
        return array_map(function (array $point) use ($width, $height): array {
            return [
                'x' => $this->scaleX($point['x'], $width),
                'y' => $this->scaleY($point['y'], $height),
            ];
        }, $points);
    }

    /**
     * @param array<int, array<int, array{char:string,color:?int,mask:int}>> $grid
     * @param array<int, array{x:int,y:int}> $points
     */
    private function rasterizeAnsiPolygonOutline(array &$grid, array $points, int $colorIndex): void
    {
        $count = count($points);
        if ($count < 2) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $next = ($i + 1) % $count;
            $this->rasterizeAnsiLine(
                $grid,
                $points[$i]['x'],
                $points[$i]['y'],
                $points[$next]['x'],
                $points[$next]['y'],
                $colorIndex
            );
        }
    }

    /**
     * @param array<int, array<int, array{char:string,color:?int,mask:int}>> $grid
     * @param array<int, array{x:int,y:int}> $points
     */
    private function fillAnsiPolygon(array &$grid, array $points, int $colorIndex): void
    {
        if (count($points) < 3) {
            return;
        }

        $minY = self::ANSI_ROWS - 1;
        $maxY = 0;
        foreach ($points as $point) {
            $minY = min($minY, $point['y']);
            $maxY = max($maxY, $point['y']);
        }

        $minY = max(0, $minY);
        $maxY = min(self::ANSI_ROWS - 1, $maxY);

        for ($y = $minY; $y <= $maxY; $y++) {
            for ($x = 0; $x < self::ANSI_COLS; $x++) {
                if ($this->pointInPolygon($x, $y, $points)) {
                    $grid[$y][$x] = [
                        'char' => '█',
                        'color' => $colorIndex,
                        'mask' => 0,
                    ];
                }
            }
        }
    }

    /**
     * @param array<int, array{x:int,y:int}> $points
     */
    private function pointInPolygon(int $x, int $y, array $points): bool
    {
        $inside = false;
        $count = count($points);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $points[$i]['x'];
            $yi = $points[$i]['y'];
            $xj = $points[$j]['x'];
            $yj = $points[$j]['y'];

            $intersects = (($yi > $y) !== ($yj > $y))
                && ($x < (($xj - $xi) * ($y - $yi)) / (($yj - $yi) ?: 1) + $xi);

            if ($intersects) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    private function scaleX(int $x, int $width): int
    {
        return (int)round(($x / max(1, $width - 1)) * (self::ANSI_COLS - 1));
    }

    private function scaleY(int $y, int $height): int
    {
        return (int)round(($y / max(1, $height - 1)) * (self::ANSI_ROWS - 1));
    }
}
