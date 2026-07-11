<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\SearchField;

final readonly class SpotSearchCriteria
{
    /**
     * @param  list<string>  $subcategories
     * @param  list<string>  $termVariants
     * @param  list<list<string>>  $metadataTermGroups
     */
    public function __construct(
        public ?string $term = null,
        public SearchField $field = SearchField::Title,
        public ?string $category = null,
        public array $subcategories = [],
        public int $perPage = 50,
        public int $page = 1,
        public string $pageName = 'page',
        public array $termVariants = [],
        public array $metadataTermGroups = [],
        public ?int $offset = null,
    ) {}
}
