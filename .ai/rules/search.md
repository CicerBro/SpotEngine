---
paths:
  - 'app/Services/Search/**'
---

# Search

## Search identifiers from normalized indexed metadata
External provider IDs use exact normalized provider:value tokens in PostgreSQL and numeric multi64 attributes in Manticore. Normalize writes in bounded bulk updates and backfill legacy null rows before using identifier filters. Manticore MVA filters need explicit any(attribute); count requests with limit zero need cutoff zero for exact totals.
