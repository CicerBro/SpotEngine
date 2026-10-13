---
paths:
  - 'app/Services/Nntp/**'
---

# Nntp

## Payload limits may close NNTP streams
Enforce cumulative byte and line bounds before accumulating NNTP payloads, including parallel buffers and compressed responses. Protocol payload rejection can close a stream; driver status checks and cleanup must tolerate an already closed resource, including QUIT failures. Keep decompression output bounded; do not restore the unbounded bzip2 fallback.

## Pipelined replies must retain framing and alignment
Compressed response readers must consume exactly one frame and preserve buffered bytes belonging to later replies. Bound outstanding HEAD requests by count and write bytes. Close the connection on transport, decoding, or callback failure while replies remain queued; those replies cannot safely be reused by later operations.

## Fatal HEAD failures must abort both batch APIs
Only definitive missing-article responses produce null/Missing results: 430 for message IDs, and 423 for integer article requests. Server, transport, framing, and callback failures must throw in both return-array and callback modes and discard unsafe sockets. Callback processing time must not consume other pooled requests' network deadlines.
