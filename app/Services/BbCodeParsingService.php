<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Parses BBCode in spot descriptions to safe HTML.
 * Supports standard BBCode per bbcode.org: formatting, links, images, quote, spoiler,
 * code, pre, lists, tables, youtube. Plain http/https URLs are auto-linked.
 * External images are not embedded (link only).
 */
class BbCodeParsingService
{
    private const string URL_PATTERN = '#(https?://[^\s<>"\']+)#u';

    private const int MAX_INPUT_LENGTH = 100_000;

    private const int MAX_RECURSION_DEPTH = 50;

    private const int MIN_FONT_SIZE = 8;

    private const int MAX_FONT_SIZE = 72;

    /** @var array<string, string> Named CSS colors allowed in [color=] */
    private const array NAMED_COLORS = [
        'black' => '#000000', 'white' => '#ffffff', 'red' => '#dc2626', 'green' => '#16a34a',
        'blue' => '#2563eb', 'yellow' => '#ca8a04', 'orange' => '#ea580c', 'purple' => '#9333ea',
        'gray' => '#6b7280', 'grey' => '#6b7280',
    ];

    private int $recursionDepth = 0;

    public function parse(?string $bbcode): string
    {
        if ($bbcode === null || $bbcode === '') {
            return '';
        }

        if (mb_strlen($bbcode, 'UTF-8') > self::MAX_INPUT_LENGTH) {
            return $this->escape($bbcode);
        }

        $this->recursionDepth = 0;

        return $this->parseFragment($bbcode);
    }

    private function parseFragment(string $input): string
    {
        if (++$this->recursionDepth > self::MAX_RECURSION_DEPTH) {
            $this->recursionDepth--;

            return $this->escape($input);
        }

        $out = '';
        $len = \strlen($input);
        $i = 0;

        while ($i < $len) {
            $char = $input[$i];

            if ($char === '[') {
                $close = strpos($input, ']', $i);
                if ($close === false) {
                    $out .= $this->linkify(substr($input, $i));
                    break;
                }

                $tagContent = substr($input, $i + 1, $close - $i - 1);
                $tagEnd = $close + 1;

                if (str_starts_with($tagContent, '/')) {
                    $out .= $this->escape('['.$tagContent.']');
                    $i = $tagEnd;

                    continue;
                }

                $parsed = $this->parseTag($tagContent, $input, $tagEnd);
                if ($parsed !== null) {
                    $out .= $parsed['html'];
                    $i = $parsed['next'];
                } else {
                    $out .= $this->linkify(substr($input, $i, $tagEnd - $i));
                    $i = $tagEnd;
                }
            } else {
                $nextBracket = strpos($input, '[', $i);
                $sliceEnd = $nextBracket !== false ? $nextBracket : $len;
                $out .= $this->linkify(substr($input, $i, $sliceEnd - $i));
                $i = $sliceEnd;
            }
        }

        $this->recursionDepth--;

        return $out;
    }

    /**
     * @return array{html: string, next: int}|null
     */
    private function parseTag(string $tagContent, string $input, int $afterBracket): ?array
    {
        $eq = strpos($tagContent, '=');
        $tagName = $eq !== false ? substr($tagContent, 0, $eq) : $tagContent;
        $tagName = strtolower(trim($tagName));
        $attr = $eq !== false ? trim(substr($tagContent, $eq + 1)) : null;

        if ($tagName === 'br') {
            return ['html' => '<br>', 'next' => $afterBracket];
        }

        if (in_array($tagName, ['b', 'i', 'u', 's'], true)) {
            $closeTag = '[/'.$tagName.']';
            $pos = $this->findClosingTagPosition($input, $closeTag, $afterBracket);
            if ($pos === false) {
                return ['html' => $this->escape('['.$tagContent.']'), 'next' => $afterBracket];
            }
            $inner = substr($input, $afterBracket, $pos - $afterBracket);
            $pair = match ($tagName) {
                'b' => ['<strong>', '</strong>'],
                'i' => ['<em>', '</em>'],
                'u' => ['<u>', '</u>'],
                's' => ['<s>', '</s>'],
            };
            $html = $pair[0].$this->parseFragment($inner).$pair[1];

            return ['html' => $html, 'next' => $pos + \strlen($closeTag)];
        }

        if ($tagName === 'size' && $attr !== null && $attr !== '') {
            $num = (int) trim($attr);
            if ($num >= self::MIN_FONT_SIZE && $num <= self::MAX_FONT_SIZE) {
                $closePos = $this->findClosingTagPosition($input, '[/size]', $afterBracket);
                if ($closePos !== false) {
                    $inner = substr($input, $afterBracket, $closePos - $afterBracket);
                    $html = '<span style="font-size:'.$num.'px">'.$this->parseFragment($inner).'</span>';

                    return ['html' => $html, 'next' => $closePos + 7];
                }
            }

            return null;
        }

        if ($tagName === 'color' && $attr !== null && $attr !== '') {
            $color = $this->sanitizeColor(trim($attr, '"\''));
            if ($color !== null) {
                $closePos = $this->findClosingTagPosition($input, '[/color]', $afterBracket);
                if ($closePos !== false) {
                    $inner = substr($input, $afterBracket, $closePos - $afterBracket);
                    $html = '<span style="color:'.$this->escapeAttr($color).'">'.$this->parseFragment($inner).'</span>';

                    return ['html' => $html, 'next' => $closePos + 8];
                }
            }

            return null;
        }

        if (in_array($tagName, ['center', 'left', 'right'], true)) {
            $closeTag = '[/'.$tagName.']';
            $pos = $this->findClosingTagPosition($input, $closeTag, $afterBracket);
            if ($pos === false) {
                return null;
            }
            $inner = substr($input, $afterBracket, $pos - $afterBracket);
            $align = $tagName === 'center' ? 'center' : ($tagName === 'right' ? 'right' : 'left');
            $html = '<div style="text-align:'.$align.'">'.$this->parseFragment($inner).'</div>';

            return ['html' => $html, 'next' => $pos + \strlen($closeTag)];
        }

        if ($tagName === 'url' && $attr !== null && $attr !== '') {
            $href = $this->sanitizeUrl(trim($attr, '"\''));
            if ($href === null) {
                return null;
            }

            $closePos = $this->findClosingTagPosition($input, '[/url]', $afterBracket);
            if ($closePos === false) {
                return null;
            }

            $inner = substr($input, $afterBracket, $closePos - $afterBracket);
            $html = '<a href="'.$this->escapeAttr($href).'" rel="noopener noreferrer nofollow" target="_blank" class="text-indigo-400 hover:text-indigo-300 underline break-all">';
            $html .= $this->parseFragment($inner);
            $html .= '</a>';

            return ['html' => $html, 'next' => $closePos + 6];
        }

        if ($tagName === 'url' && ($attr === null || $attr === '')) {
            $closePos = $this->findClosingTagPosition($input, '[/url]', $afterBracket);
            if ($closePos === false) {
                return null;
            }
            $inner = trim(substr($input, $afterBracket, $closePos - $afterBracket));
            $href = $this->sanitizeUrl($inner);
            if ($href === null) {
                return null;
            }
            $html = '<a href="'.$this->escapeAttr($href).'" rel="noopener noreferrer nofollow" target="_blank" class="text-indigo-400 hover:text-indigo-300 underline break-all">'.$this->escapeAttr($inner).'</a>';

            return ['html' => $html, 'next' => $closePos + 6];
        }

        if ($tagName === 'quote') {
            $closePos = $this->findClosingTagPosition($input, '[/quote]', $afterBracket);
            if ($closePos === false) {
                return null;
            }
            $inner = substr($input, $afterBracket, $closePos - $afterBracket);
            $cite = ($attr !== null && $attr !== '') ? trim($attr, '"\'') : null;
            $html = '<blockquote class="border-l-4 border-gray-600 pl-4 my-2 text-gray-400">';
            if ($cite !== null) {
                $html .= '<cite class="block font-semibold text-gray-300 mb-1">'.$this->escape($cite).' wrote:</cite>';
            }
            $html .= $this->parseFragment($inner).'</blockquote>';

            return ['html' => $html, 'next' => $closePos + 8];
        }

        if ($tagName === 'spoiler') {
            $closePos = $this->findClosingTagPosition($input, '[/spoiler]', $afterBracket);
            if ($closePos === false) {
                return null;
            }
            $inner = substr($input, $afterBracket, $closePos - $afterBracket);
            $summary = ($attr !== null && $attr !== '') ? trim($attr, '"\'') : 'Spoiler';
            $html = '<details class="my-2"><summary class="cursor-pointer text-gray-400 hover:text-gray-300">'.$this->escape($summary).'</summary><div class="mt-1">'.$this->parseFragment($inner).'</div></details>';

            return ['html' => $html, 'next' => $closePos + 10];
        }

        if ($tagName === 'code') {
            $closePos = $this->findClosingTagPosition($input, '[/code]', $afterBracket);
            if ($closePos === false) {
                return null;
            }
            $raw = substr($input, $afterBracket, $closePos - $afterBracket);
            $lang = ($attr !== null && $attr !== '') ? ' language-'.trim((string) preg_replace('/[^a-z0-9_-]/i', '', $attr)) : '';
            $html = '<pre class="bg-gray-800 rounded p-3 overflow-x-auto text-sm my-2"><code'.$lang.'>'.$this->escape($raw).'</code></pre>';

            return ['html' => $html, 'next' => $closePos + 7];
        }

        if ($tagName === 'pre') {
            $closePos = $this->findClosingTagPosition($input, '[/pre]', $afterBracket);
            if ($closePos === false) {
                return null;
            }
            $raw = substr($input, $afterBracket, $closePos - $afterBracket);
            $html = '<pre class="bg-gray-800 rounded p-3 overflow-x-auto text-sm my-2 whitespace-pre">'.$this->escape($raw).'</pre>';

            return ['html' => $html, 'next' => $closePos + 6];
        }

        if (in_array($tagName, ['list', 'ul', 'ol'], true)) {
            $closeTag = '[/'.$tagName.']';
            $pos = $this->findClosingTagPosition($input, $closeTag, $afterBracket);
            if ($pos === false) {
                return null;
            }
            $inner = substr($input, $afterBracket, $pos - $afterBracket);
            $listHtml = $this->parseListItems($inner);
            $ordered = $tagName === 'ol' || ($tagName === 'list' && $attr !== null && $attr !== '');
            $tag = $ordered ? 'ol' : 'ul';
            $html = '<'.$tag.' class="list-disc list-inside my-2 space-y-1">'.$listHtml.'</'.$tag.'>';

            return ['html' => $html, 'next' => $pos + \strlen($closeTag)];
        }

        if ($tagName === 'li') {
            $closePos = $this->findClosingTagPosition($input, '[/li]', $afterBracket);
            if ($closePos === false) {
                return null;
            }
            $inner = substr($input, $afterBracket, $closePos - $afterBracket);

            return ['html' => '<li>'.$this->parseFragment($inner).'</li>', 'next' => $closePos + 5];
        }

        if ($tagName === 'table') {
            $closePos = $this->findClosingTagPosition($input, '[/table]', $afterBracket);
            if ($closePos === false) {
                return null;
            }
            $inner = substr($input, $afterBracket, $closePos - $afterBracket);
            $html = '<div class="overflow-x-auto my-2"><table class="min-w-full border border-gray-600 border-collapse">'.$this->parseFragment($inner).'</table></div>';

            return ['html' => $html, 'next' => $closePos + 8];
        }

        if ($tagName === 'tr') {
            $closePos = $this->findClosingTagPosition($input, '[/tr]', $afterBracket);
            if ($closePos === false) {
                return null;
            }
            $inner = substr($input, $afterBracket, $closePos - $afterBracket);

            return ['html' => '<tr>'.$this->parseFragment($inner).'</tr>', 'next' => $closePos + 5];
        }

        if ($tagName === 'th') {
            $closePos = $this->findClosingTagPosition($input, '[/th]', $afterBracket);
            if ($closePos === false) {
                return null;
            }
            $inner = substr($input, $afterBracket, $closePos - $afterBracket);

            return ['html' => '<th class="border border-gray-600 px-3 py-2 text-left bg-gray-800">'.$this->parseFragment($inner).'</th>', 'next' => $closePos + 5];
        }

        if ($tagName === 'td') {
            $closePos = $this->findClosingTagPosition($input, '[/td]', $afterBracket);
            if ($closePos === false) {
                return null;
            }
            $inner = substr($input, $afterBracket, $closePos - $afterBracket);

            return ['html' => '<td class="border border-gray-600 px-3 py-2">'.$this->parseFragment($inner).'</td>', 'next' => $closePos + 5];
        }

        if ($tagName === 'youtube') {
            $closePos = $this->findClosingTagPosition($input, '[/youtube]', $afterBracket);
            if ($closePos === false) {
                return null;
            }
            $id = trim(substr($input, $afterBracket, $closePos - $afterBracket));
            if ($id !== '' && preg_match('/^[a-zA-Z0-9_-]{10,11}$/', $id)) {
                $html = '<span class="inline-block my-1"><a href="https://www.youtube.com/watch?v='.$this->escapeAttr($id).'" rel="noopener noreferrer nofollow" target="_blank" class="text-indigo-400 hover:text-indigo-300 underline">Watch on YouTube ('.$this->escape($id).')</a></span>';

                return ['html' => $html, 'next' => $closePos + 10];
            }

            return null;
        }

        if ($tagName === 'img') {
            $closePos = $this->findClosingTagPosition($input, '[/img]', $afterBracket);
            if ($closePos === false) {
                return null;
            }

            $urlRaw = trim(substr($input, $afterBracket, $closePos - $afterBracket));
            $href = $this->sanitizeUrl($urlRaw);
            if ($href === null) {
                return ['html' => $this->escape('['.$tagContent.']'.substr($input, $afterBracket, $closePos - $afterBracket).'[/img]'), 'next' => $closePos + 6];
            }

            $html = '<a href="'.$this->escapeAttr($href).'" rel="noopener noreferrer nofollow" target="_blank" class="inline-flex items-center gap-1 px-2 py-1 rounded bg-gray-700 text-gray-300 hover:bg-gray-600 text-xs border border-gray-600">';
            $html .= '<span aria-hidden="true">🖼</span> View image';
            $html .= '</a>';

            return ['html' => $html, 'next' => $closePos + 6];
        }

        return null;
    }

    private function findClosingTagPosition(string $input, string $closeTag, int $offset): int|false
    {
        $position = strpos($input, $closeTag, $offset);
        if ($position !== false) {
            return $position;
        }

        return stripos($input, $closeTag, $offset);
    }

    private function parseListItems(string $inner): string
    {
        $out = '';
        $start = 0;
        $len = \strlen($inner);
        while ($start < $len) {
            $pos = stripos($inner, '[*]', $start);
            if ($pos === false) {
                $segment = substr($inner, $start);
                if ($segment !== '') {
                    $out .= '<li>'.$this->parseFragment($segment).'</li>';
                }
                break;
            }
            $segment = substr($inner, $start, $pos - $start);
            $out .= '<li>'.$this->parseFragment($segment).'</li>';
            $start = $pos + 3;
        }

        return $out;
    }

    private function sanitizeColor(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $valueLower = strtolower($value);
        if (isset(self::NAMED_COLORS[$valueLower])) {
            return self::NAMED_COLORS[$valueLower];
        }
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
            return $value;
        }

        return null;
    }

    private function sanitizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (strncasecmp($url, 'http://', 7) === 0 || strncasecmp($url, 'https://', 8) === 0) {
            return $url;
        }

        return null;
    }

    private function linkify(string $text): string
    {
        if (! str_contains($text, 'http://') && ! str_contains($text, 'https://')) {
            return $this->escape($text);
        }

        $parts = preg_split(self::URL_PATTERN, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return $this->escape($text);
        }

        $out = '';

        foreach ($parts as $part) {
            if (! str_starts_with($part, 'http://') && ! str_starts_with($part, 'https://')) {
                $out .= $this->escape($part);

                continue;
            }

            $url = rtrim($part, '.,;:!?)');
            $href = $this->sanitizeUrl($url);

            if ($href !== null) {
                $trailing = \strlen($part) > \strlen($url) ? $this->escape(substr($part, \strlen($url))) : '';
                $out .= '<a href="'.$this->escapeAttr($href).'" rel="noopener noreferrer nofollow" target="_blank" class="text-indigo-400 hover:text-indigo-300 underline break-all">'.$this->escapeAttr($url).'</a>'.$trailing;
            } else {
                $out .= $this->escape($part);
            }
        }

        return $out;
    }

    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function escapeAttr(string $s): string
    {
        return $this->escape($s);
    }
}
