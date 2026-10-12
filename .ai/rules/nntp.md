---
paths:
  - 'app/Services/Nntp/**'
---

# Nntp

## Payload limits may close NNTP streams
Enforce cumulative byte and line bounds before accumulating NNTP payloads, including parallel buffers and compressed responses. Protocol payload rejection can close a stream; driver status checks and cleanup must tolerate an already closed resource, including QUIT failures. Keep decompression output bounded; do not restore the unbounded bzip2 fallback.
