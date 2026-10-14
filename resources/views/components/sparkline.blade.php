{{--
    Minecraft Toolkit — shared inline-SVG sparkline (Performance page tiles + charts).

    Props:
        series        list<float> — raw y values; non-numerics are skipped silently.
        heightClasses Tailwind height utilities for the rendered box ("h-9", "h-24"...).
                      Sizing only — never touch viewBox scaling logic below.
        ariaLabel     Optional accessible description; component is aria-hidden otherwise.

    Geometry contract (deterministic):
      - viewBox "0 0 100 30", preserveAspectRatio="none" so it stretches to the parent card.
      - Baseline scaling starts at MIN = 0; the MAX is derived from the data.
      - Flatline guard: an all-zero / empty / single-point series degrades to a constant
        mid-line at y = 15 with NO divide-by-zero anywhere and no console noise.
      - Line: <polyline> stroke primary-600 (dark: primary-400), stroke-width 2, fill none,
        vector-effect keeps the literal 2px even when stretched.
      - Area: <polygon> closed onto the bottom edge, color fill at opacity 10%.
      - No point markers: the viewBox stretches non-uniformly, so circles turn into smears.
--}}
@props([
    'series' => [],
    'heightClasses' => 'h-9',
    'ariaLabel' => null,
])
@php
    $values = [];
    foreach ((array) $series as $value) {
        if (is_numeric($value)) {
            $values[] = floatval($value);
        }
    }

    $count = count($values);

    // Deterministic scale: floor 0, ceiling 25% above the data's peak, so a steady series
    // reads as a line with headroom instead of a solid block filling the card.
    $max = $count > 0 ? max($values) * 1.25 : 0.0;
    $flat = $count === 0 || $max <= 0.000001;

    $padTop = 2.0;
    $padBottom = 2.0;
    $plotHeight = 30.0 - $padTop - $padBottom;

    $pointsX = [];
    $pointsY = [];

    for ($i = 0; $i < $count; $i++) {
        if ($flat) {
            // Midline degradation: no divide-by-zero, constant band (silent).
            $x = $count === 1 ? 50.0 : ($i / max($count - 1, 1)) * 100.0;
            $y = 15.0;
        } elseif ($count === 1) {
            $x = 50.0;
            $clamped = min(max($values[0], 0.0), $max);
            $y = 30.0 - $padBottom - ($clamped / $max) * $plotHeight;
        } else {
            $x = ($i / ($count - 1)) * 100.0;
            $clamped = min(max($values[$i], 0.0), $max);
            $y = 30.0 - $padBottom - ($clamped / $max) * $plotHeight;
        }

        $pointsX[] = round($x, 3);
        $pointsY[] = round($y, 3);
    }

    // One sample is a level, not a peak: draw it across the whole width (a lone point
    // closed onto the baseline rendered as a triangle).
    if ($count === 1) {
        $pointsX = [0.0, 100.0];
        $pointsY = [$pointsY[0], $pointsY[0]];
        $count = 2;
    }

    $segments = '';
    for ($i = 0; $i < $count; $i++) {
        $segments .= sprintf('%.2F,%.2F ', $pointsX[$i], $pointsY[$i]);
    }
    $segments = trim($segments);

    $areaSegments = $segments === '' ? '' : $segments . ' 100.00,30.00 0.00,30.00';

    $lastX = $count > 0 ? end($pointsX) : null;
    $lastY = $count > 0 ? end($pointsY) : null;
@endphp

<svg
    viewBox="0 0 100 30"
    preserveAspectRatio="none"
    class="block w-full {{ $heightClasses }}"
    @if (filled($ariaLabel))
        role="img" aria-label="{{ $ariaLabel }}"
    @else
        aria-hidden="true"
    @endif
>
    @if ($areaSegments !== '')
        <polygon
            points="{{ $areaSegments }}"
            opacity="0.10"
            class="fill-primary-600 dark:fill-primary-400"
        />
        <polyline
            points="{{ $segments }}"
            fill="none"
            stroke-width="2"
            vector-effect="non-scaling-stroke"
            class="stroke-primary-600 dark:stroke-primary-400"
        />
    @endif
</svg>
