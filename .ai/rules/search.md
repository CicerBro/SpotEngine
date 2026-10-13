---
paths:
  - 'app/Services/Search/**'
---

# Search

## Search identifiers from normalized indexed metadata
External provider IDs use exact normalized provider:value tokens in PostgreSQL and numeric multi64 attributes in Manticore. Normalize writes in bounded bulk updates and backfill legacy null rows before using identifier filters. Manticore MVA filters need explicit any(attribute); count requests with limit zero need cutoff zero for exact totals.

## Group facets and fold PostgreSQL singleton predicates
Subcategory facets are keyed by root category plus letter: OR within a facet, AND across facets, with the same grouping in PostgreSQL and Manticore. Fold singleton PostgreSQL facets into one JSONB containment array so selective combinations can use the GIN index; separate predicates can cause a full ordered scan. Keep alternative groups compact without Cartesian expansion.
