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
        public ?string $cursor = null,
        public bool $unreadOnly = false,
        public ?\DateTimeInterface $unreadSince = null,
    ) {}

    /**
     * @return list<list<string>>
     */
    public function subcategoryGroups(): array
    {
        $groups = [];

        foreach ($this->subcategories as $subcategory) {
            $group = preg_match('/^(\d{2}[a-z])\d+$/D', $subcategory, $matches) === 1
                ? $matches[1]
                : 'other';

            $groups[$group][] = $subcategory;
        }

        ksort($groups, SORT_STRING);

        foreach ($groups as $group => $values) {
            $values = array_values(array_unique($values));
            sort($values, SORT_STRING);
            $groups[$group] = $values;
        }

        return array_values($groups);
    }
}
