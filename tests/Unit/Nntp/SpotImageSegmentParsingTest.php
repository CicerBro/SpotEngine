<?php

declare(strict_types=1);

use App\Services\Nntp\SpotParser;

test('parseXml preserves every multipart preview image segment in posting order', function () {
    $xml = <<<'XML'
    <Spotnet><Posting>
        <Title>Multipart preview</Title>
        <Category>01</Category>
        <Image>
            <Segment>&lt;first-part@spot.net&gt;</Segment>
            <Segment>second-part@spot.net</Segment>
        </Image>
    </Posting></Spotnet>
    XML;

    $spot = (new SpotParser)->parseXml($xml);

    expect($spot)->not->toBeNull()
        ->and($spot['image_segment'])->toBe('first-part@spot.net')
        ->and($spot['image_segments'])->toBe([
            'first-part@spot.net',
            'second-part@spot.net',
        ]);
});
