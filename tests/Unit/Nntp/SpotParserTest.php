<?php

declare(strict_types=1);

use App\Services\Nntp\SpotParser;

test('parseXml clears libxml errors for invalid xml payloads', function () {
    $parser = new SpotParser;
    $originalUseInternalErrors = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $result = $parser->parseXml('<<<not-valid-xml>>>');

    expect($result)->toBeNull();
    expect(libxml_get_errors())->toBe([]);

    libxml_use_internal_errors($originalUseInternalErrors);
});
