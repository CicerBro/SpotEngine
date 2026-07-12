<?php

declare(strict_types=1);

use App\Services\Nntp\SpotnetHeaderParser;

test('preserves repeated Spotnet fields and folded continuations', function () {
    $parser = new SpotnetHeaderParser;

    $headers = $parser->parse([
        'Subject: ignored',
        'X-XML: <Spotnet>',
        'X-XML: <Title>Test</Title>',
        "\t</Spotnet>",
        'From: =?UTF-8?Q?Jos=C3=A9?= <poster@test>',
    ], ['x-xml' => true, 'from' => true]);

    expect($headers['x-xml'])->toBe('<Spotnet><Title>Test</Title></Spotnet>')
        ->and($headers['from'])->toContain('José');
});
