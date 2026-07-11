<?php

declare(strict_types=1);

use App\Services\BbCodeParsingService;

beforeEach(function () {
    $this->parser = new BbCodeParsingService;
});

test('is scoped to the current Laravel lifecycle', function () {
    $first = app(BbCodeParsingService::class);
    $second = app(BbCodeParsingService::class);

    app()->forgetScopedInstances();

    $third = app(BbCodeParsingService::class);

    expect($first)->toBe($second)
        ->and($third)->not->toBe($first);
});

test('returns empty string for null or empty input', function () {
    expect($this->parser->parse(null))->toBe('');
    expect($this->parser->parse(''))->toBe('');
});

test('escapes plain text', function () {
    $input = 'Hello <script>alert(1)</script> & "quotes"';
    $out = $this->parser->parse($input);

    expect($out)->not->toContain('<script>');
    expect($out)->toContain('&lt;script&gt;');
    expect($out)->toContain('&amp;');
    expect($out)->toContain('&quot;quotes&quot;');
});

test('parses bold and italic tags', function () {
    expect($this->parser->parse('[b]bold[/b]'))->toBe('<strong>bold</strong>');
    expect($this->parser->parse('[i]italic[/i]'))->toBe('<em>italic</em>');
    expect($this->parser->parse('[b]bold [i]both[/i][/b]'))->toBe('<strong>bold <em>both</em></strong>');
});

test('parses br tag', function () {
    expect($this->parser->parse('a[br]b'))->toBe('a<br>b');
});

test('parses url tag with valid http url', function () {
    $out = $this->parser->parse('[url=https://example.com]click[/url]');

    expect($out)->toContain('href="https://example.com"');
    expect($out)->toContain('rel="noopener noreferrer nofollow"');
    expect($out)->toContain('target="_blank"');
    expect($out)->toContain('>click</a>');
});

test('parses url tag with https', function () {
    $out = $this->parser->parse('[url=https://discord.gg/5f2u7PK3pu]Discord[/url]');

    expect($out)->toContain('href="https://discord.gg/5f2u7PK3pu"');
    expect($out)->toContain('>Discord</a>');
});

test('normalizes url tag hrefs through the URI parser', function () {
    $parser = new BbCodeParsingService;
    $out = $parser->parse('[url=HTTPS://Example.COM/path]Example[/url]');

    expect($out)->toContain('href="https://example.com/path"');
    expect($out)->toContain('>Example</a>');
});

test('plain urls in text become clickable links', function () {
    $out = $this->parser->parse('Check https://example.com and http://test.org for more.');

    expect($out)->toContain('href="https://example.com"');
    expect($out)->toContain('>https://example.com</a>');
    expect($out)->toContain('href="http://test.org"');
    expect($out)->toContain('>http://test.org</a>');
});

test('plain urls with uppercase schemes become clickable normalized links', function () {
    $parser = new BbCodeParsingService;
    $out = $parser->parse('Check HTTPS://Example.COM/path for more.');

    expect($out)->toContain('href="https://example.com/path"');
    expect($out)->toContain('>HTTPS://Example.COM/path</a>');
});

test('rejects javascript url', function () {
    $out = $this->parser->parse('[url=javascript:alert(1)]x[/url]');

    expect($out)->not->toContain('href="javascript:');
    expect($out)->toContain('[url=javascript:alert(1)]');
});

test('rejects data url', function () {
    $out = $this->parser->parse('[url=data:text/html,<script>]x[/url]');

    expect($out)->not->toContain('href="data:');
});

test('rejects http urls without a host', function () {
    $parser = new BbCodeParsingService;
    $out = $parser->parse('[url=https:///missing-host]x[/url]');

    expect($out)->not->toContain('href="https:///missing-host"');
    expect($out)->toContain('[url=https:///missing-host]');
});

test('parses img tag as view image link without embedding', function () {
    $out = $this->parser->parse('[img]https://i.postimg.cc/Kz4T9pwg/532.jpg[/img]');

    expect($out)->toContain('href="https://i.postimg.cc/Kz4T9pwg/532.jpg"');
    expect($out)->toContain('View image');
    expect($out)->not->toContain('<img ');
});

test('rejects img with non-http url', function () {
    $out = $this->parser->parse('[img]javascript:void(0)[/img]');

    expect($out)->not->toContain('href="javascript:');
});

test('leaves unknown tags escaped', function () {
    $out = $this->parser->parse('[script]evil[/script]');

    expect($out)->toContain('[script]');
    expect($out)->toContain('evil');
    expect($out)->toContain('[/script]');
});

test('real world bbcode snippet parses correctly', function () {
    $input = '[b]GP-NZB-Poster-Groep[/b] ..( https://streamable.com/jhzrad ) plak link in je browser[br]De nummer 1 poster groep';
    $out = $this->parser->parse($input);

    expect($out)->toContain('<strong>GP-NZB-Poster-Groep</strong>');
    expect($out)->toContain('<br>');
    expect($out)->toContain('De nummer 1 poster groep');
});

test('over max length returns escaped only', function () {
    $long = str_repeat('x', 100_001);
    $out = $this->parser->parse($long);

    expect($out)->toBe($long);
    expect(strlen($out))->toBe(100_001);
});

test('long description with many tags parses without hanging', function () {
    $chunks = [];
    for ($i = 0; $i < 500; $i++) {
        $chunks[] = '[b]bold ' . $i . '[/b] [br]';
    }
    $input = implode('', $chunks);

    $start = microtime(true);
    $out = $this->parser->parse($input);
    $elapsed = microtime(true) - $start;

    expect($out)->toContain('<strong>bold 0</strong>');
    expect($out)->toContain('<strong>bold 499</strong>');
    expect($elapsed)->toBeLessThan(2.0);
});

test('parses underline and strikethrough tags', function () {
    expect($this->parser->parse('[u]underline[/u]'))->toContain('<u>underline</u>');
    expect($this->parser->parse('[s]strike[/s]'))->toContain('<s>strike</s>');
});

test('parses size tag with valid range', function () {
    $out = $this->parser->parse('[size=24]big[/size]');
    expect($out)->toContain('font-size:24px');
    expect($out)->toContain('big');
});

test('parses color tag with hex and named colors', function () {
    $out = $this->parser->parse('[color=#ff0000]red[/color]');
    expect($out)->toContain('color:#ff0000');
    $out = $this->parser->parse('[color=blue]blue[/color]');
    expect($out)->toContain('color:#2563eb');
});

test('parses center left right alignment', function () {
    expect($this->parser->parse('[center]centered[/center]'))->toContain('text-align:center');
    expect($this->parser->parse('[left]left[/left]'))->toContain('text-align:left');
    expect($this->parser->parse('[right]right[/right]'))->toContain('text-align:right');
});

test('parses url with url as body', function () {
    $out = $this->parser->parse('[url]https://example.com[/url]');
    expect($out)->toContain('href="https://example.com"');
    expect($out)->toContain('>https://example.com</a>');
});

test('parses quote and named quote', function () {
    $out = $this->parser->parse('[quote]said this[/quote]');
    expect($out)->toContain('<blockquote');
    expect($out)->toContain('said this');
    $out = $this->parser->parse('[quote=Alice]hello[/quote]');
    expect($out)->toContain('Alice');
    expect($out)->toContain('wrote:');
});

test('parses spoiler and named spoiler', function () {
    $out = $this->parser->parse('[spoiler]secret[/spoiler]');
    expect($out)->toContain('<details');
    expect($out)->toContain('<summary');
    expect($out)->toContain('secret');
    $out = $this->parser->parse('[spoiler=Hint]answer[/spoiler]');
    expect($out)->toContain('Hint');
});

test('parses code and pre without parsing inner bbcode', function () {
    $out = $this->parser->parse('[code][b]not bold[/b][/code]');
    expect($out)->not->toContain('<strong>');
    expect($out)->toContain('[b]not bold[/b]');
    $out = $this->parser->parse('[pre]  spaces[/pre]');
    expect($out)->toContain('<pre');
    expect($out)->toContain('  spaces');
});

test('parses list with star items', function () {
    $out = $this->parser->parse('[list][*]one[*]two[/list]');
    expect($out)->toContain('<ul');
    expect($out)->toContain('<li>one</li>');
    expect($out)->toContain('<li>two</li>');
});

test('parses list with li tags', function () {
    $out = $this->parser->parse('[ul][li]a[/li][li]b[/li][/ul]');
    expect($out)->toContain('<li>a</li>');
    expect($out)->toContain('<li>b</li>');
});

test('parses ordered list', function () {
    $out = $this->parser->parse('[ol][li]first[/li][/ol]');
    expect($out)->toContain('<ol');
});

test('parses table tags', function () {
    $out = $this->parser->parse('[table][tr][th]H[/th][td]C[/td][/tr][/table]');
    expect($out)->toContain('<table');
    expect($out)->toContain('<tr>');
    expect($out)->toContain('<th');
    expect($out)->toContain('H');
    expect($out)->toContain('<td');
    expect($out)->toContain('C');
});

test('parses youtube tag', function () {
    $out = $this->parser->parse('[youtube]dQw4w9WgXcQ[/youtube]');
    expect($out)->toContain('youtube.com/watch?v=');
    expect($out)->toContain('dQw4w9WgXcQ');
});

test('rejects invalid color to prevent injection', function () {
    $out = $this->parser->parse('[color=red; expression(1)]x[/color]');
    expect($out)->not->toContain('style="color:red; expression');
});
