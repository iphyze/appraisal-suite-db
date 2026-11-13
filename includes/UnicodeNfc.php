<?php
/**
 * NFC normalisation, with a portable Unicode 15.1 fallback for PHP hosts
 * without ext-intl. This helper does not perform NFKC/compatibility folding.
 *
 * PHP's native Normalizer is preferred where available. The fallback follows
 * Unicode canonical decomposition, canonical ordering, and composition.
 */

function appNfcCodePoint(string $char): int
{
    $first = ord($char[0]);
    if ($first < 0x80) {
        return $first;
    }
    if ($first < 0xE0) {
        return (($first & 0x1F) << 6) | (ord($char[1]) & 0x3F);
    }
    if ($first < 0xF0) {
        return (($first & 0x0F) << 12) | ((ord($char[1]) & 0x3F) << 6) | (ord($char[2]) & 0x3F);
    }
    return (($first & 0x07) << 18) | ((ord($char[1]) & 0x3F) << 12)
        | ((ord($char[2]) & 0x3F) << 6) | (ord($char[3]) & 0x3F);
}

function appNfcUtf8(int $cp): string
{
    if ($cp < 0x80) {
        return chr($cp);
    }
    if ($cp < 0x800) {
        return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
    }
    if ($cp < 0x10000) {
        return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }
    return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F))
        . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
}

/** @param array<int,list<int>> $decompositions @param list<int> $output */
function appNfcDecompose(int $cp, array $decompositions, array &$output): void
{
    // Hangul's 11,172 syllables use the standard algorithm, not a lookup table.
    $sIndex = $cp - 0xAC00;
    if ($sIndex >= 0 && $sIndex < 11172) {
        $output[] = 0x1100 + intdiv($sIndex, 588);
        $output[] = 0x1161 + intdiv($sIndex % 588, 28);
        if ($sIndex % 28 !== 0) {
            $output[] = 0x11A7 + ($sIndex % 28);
        }
        return;
    }
    if (isset($decompositions[$cp])) {
        foreach ($decompositions[$cp] as $part) {
            appNfcDecompose($part, $decompositions, $output);
        }
        return;
    }
    $output[] = $cp;
}

function appNfcHangulCompose(int $starter, int $cp): ?int
{
    $lIndex = $starter - 0x1100;
    $vIndex = $cp - 0x1161;
    if ($lIndex >= 0 && $lIndex < 19 && $vIndex >= 0 && $vIndex < 21) {
        return 0xAC00 + (($lIndex * 21 + $vIndex) * 28);
    }
    $sIndex = $starter - 0xAC00;
    $tIndex = $cp - 0x11A7;
    if ($sIndex >= 0 && $sIndex < 11172 && ($sIndex % 28) === 0
        && $tIndex > 0 && $tIndex < 28) {
        return $starter + $tIndex;
    }
    return null;
}

function appUnicodeNfc(string $text): string
{
    if (!preg_match('//u', $text)) {
        throw new InvalidArgumentException('Translation source contains invalid UTF-8.');
    }
    if ($text === '' || !preg_match('/[^\x00-\x7f]/', $text)) {
        return $text;
    }
    if (class_exists('Normalizer')) {
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);
        if ($normalized === false) {
            throw new InvalidArgumentException('Unable to normalize translation source.');
        }
        return $normalized;
    }

    static $data = null;
    if ($data === null) {
        $data = require __DIR__ . '/UnicodeNfcData.php';
    }
    $parts = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if ($parts === false) {
        throw new InvalidArgumentException('Translation source contains invalid UTF-8.');
    }
    $decomposed = [];
    foreach ($parts as $part) {
        appNfcDecompose(appNfcCodePoint($part), $data['decompositions'], $decomposed);
    }

    // Canonical reordering: stable sort combining marks after each starter.
    $ordered = [];
    foreach ($decomposed as $cp) {
        $class = $data['combining_classes'][$cp] ?? 0;
        $index = count($ordered);
        $ordered[] = $cp;
        while ($class !== 0 && $index > 0) {
            $before = $data['combining_classes'][$ordered[$index - 1]] ?? 0;
            if ($before === 0 || $before <= $class) {
                break;
            }
            $ordered[$index] = $ordered[$index - 1];
            $ordered[--$index] = $cp;
        }
    }

    $composed = [];
    $starterPos = null;
    $lastClass = 0;
    foreach ($ordered as $cp) {
        $class = $data['combining_classes'][$cp] ?? 0;
        if ($starterPos !== null) {
            $starter = $composed[$starterPos];
            $candidate = appNfcHangulCompose($starter, $cp)
                ?? ($data['compositions'][$starter][$cp] ?? null);
            if ($candidate !== null && ($lastClass === 0 || $lastClass < $class)) {
                $composed[$starterPos] = $candidate;
                continue;
            }
        }
        if ($class === 0) {
            $starterPos = count($composed);
        }
        $composed[] = $cp;
        $lastClass = $class;
    }

    $out = '';
    foreach ($composed as $cp) {
        $out .= appNfcUtf8($cp);
    }
    return $out;
}
