# AI ChatHub — Concurrency Handling R&D Report

**Subject:** Runtime selection for high-concurrency AI streaming endpoints
**Scope:** `ai-gateway-service`, `chat-service`
**Status:** Research & Recommendation

---

## 1. Executive Summary

AI ChatHub's Phase 1 target is **1,000–10,000 concurrent users**. The current runtime for all
backend services is standard PHP-FPM behind Nginx — a synchronous, one-process-per-request model.
This report evaluates whether that model can meet the concurrency target, identifies the specific
architectural bottleneck that would prevent it from doing so, researches the three realistic
alternatives (Laravel Octane on **Swoole**, Laravel Octane on **RoadRunner**, and **FrankenPHP**),
and recommends an approach.

**Recommendation:** Adopt **Laravel Octane with the Swoole driver**, applied specifically to
`ai-gateway-service` and `chat-service` — the two services that hold long-lived streaming
connections open. The remaining seven services (ordinary request/response CRUD — auth,
subscription, wallet, payment, billing, notification, the API gateway) should remain on PHP-FPM,
since they have no long-lived connections to benefit from a persistent-worker runtime.

---

## 2. Problem Statement

### 2.1 Why concurrency is architecturally different for this app

Most of AI ChatHub's API surface is ordinary CRUD: a request arrives, a database query runs, a
JSON response returns in well under a second. PHP-FPM handles that pattern efficiently — a worker
process is occupied for milliseconds, then immediately free for the next request.

The AI chat endpoints are structurally different. When a user sends a message, the server opens a
Server-Sent-Events (SSE) stream to the AI provider (OpenAI, Anthropic, Gemini, etc.) and relays
tokens back to the client as they arrive — a connection that can legitimately stay open for many
seconds, sometimes longer for complex responses. **A PHP-FPM worker is occupied for the entire
duration of that stream, not just the time to schedule it.**

### 2.2 The concrete bottleneck

PHP-FPM's process pool has a hard ceiling (`pm.max_children`) — the maximum number of PHP
processes it will run simultaneously. Under the current, unconfigured PHP-FPM defaults, that
ceiling is small (single digits). Every one of those processes is a full OS process holding real
memory. To support even a modest number of users mid-stream at the same instant, the pool would
need to scale into the hundreds or thousands of processes — each a full PHP + Laravel framework
instance. This does not scale linearly on a single host: memory footprint, process-table pressure,
and OS scheduling overhead all grow with it. Raising `pm.max_children` buys some headroom, but it
cannot reach four-digit concurrent streaming connections on realistic hardware — the model itself
is the ceiling, not just its configuration.

### 2.3 What's actually needed

An architecture where **one worker process can hold many concurrent connections open at once**,
using non-blocking I/O instead of one OS process per connection. This is precisely the problem
event-loop runtimes solve, and it's why this report evaluates event-loop-based alternatives to
PHP-FPM rather than simply tuning PHP-FPM further.

---

## 3. Candidate Approaches

All three candidates share a common idea: **boot the Laravel application once, keep it resident in
memory, and serve many requests from that single boot** — eliminating the framework bootstrap cost
per request and, more importantly here, removing the one-process-per-connection ceiling for
long-lived streams. They differ in *how* they achieve this.

### 3.1 Laravel Octane — Swoole driver

Swoole is a PHP extension (written in C, installed via PECL) that adds an async, event-driven
runtime directly into the PHP process itself — including a built-in HTTP server, coroutines, and
async I/O primitives. Laravel Octane uses it as one of its two most established drivers.

- **Maturity:** The oldest and most widely deployed of the three candidates (in production PHP use
  since roughly 2012). Extensive documentation, a large user base specifically pairing it with
  Laravel Octane, and the deepest well of community troubleshooting knowledge for exactly this
  combination.
- **Architecture:** Runs as a PHP extension — the event loop lives inside the same process as the
  application code. Supports coroutines for true concurrent I/O within a single worker, not just
  multiple worker processes.
- **Operational cost:** Requires compiling a C extension at build time (`pecl install swoole`),
  which adds build-toolchain dependencies to the Docker image (and, on Alpine specifically, a
  `linux-headers` package for `ext-sockets`, which Swoole depends on). This is a one-time Docker
  image concern, not a runtime one.
- **Streaming support:** Well-proven for SSE/streaming use cases — this is one of Swoole's original
  design targets, and Octane's Swoole integration captures streamed output via output buffering
  (`ob_start()`) around the response-send step, which works transparently for standard
  echo/flush-based streaming code.

### 3.2 Laravel Octane — RoadRunner driver

RoadRunner is a separate standalone binary written in Go that manages a pool of PHP worker
processes, communicating with them over a lightweight RPC protocol rather than living inside the
PHP process itself.

- **Maturity:** Well-established, actively maintained, a credible second option to Swoole for
  Octane. No C extension to compile — RoadRunner ships as a single binary, which is a genuine
  operational simplification over Swoole's build step.
- **Architecture:** Go-based process orchestration outside PHP, rather than an in-process
  extension. This gives a degree of isolation: the runtime managing worker lifecycle is a separate
  process from PHP itself.
- **Streaming support — a specific, verifiable limitation worth flagging:** Octane's RoadRunner
  client only recognizes a streamed-response callback as stream-able if the closure has an
  **explicitly declared return type** of `Generator` or `string` (confirmed by inspecting Octane's
  `RoadRunnerClient::resolveStreamResponseCallback()`, which uses `ReflectionFunction` to check
  `hasReturnType()` before treating a callback as streamable). Any package or piece of code that
  builds a streaming response with an untyped generator closure — a common, unremarkable pattern —
  will silently fail to stream under RoadRunner unless that exact detail is present. This is not a
  theoretical concern: `laravel/ai` (the SDK this project uses for provider integration) builds its
  Vercel-protocol streaming response with exactly this untyped-closure pattern as of its current
  release. Adopting RoadRunner would mean auditing every streaming code path — first-party and
  third-party — for this specific requirement, or working around it.

### 3.3 FrankenPHP

FrankenPHP is a newer runtime, built on top of the Caddy web server (also Go-based), that embeds
the PHP interpreter directly into the Caddy binary via cgo — a different integration model from
both of the above.

- **Maturity:** The youngest of the three candidates (first released ~2022–2023). Smaller
  community, fewer battle-tested large-scale production deployments, and comparatively little
  documentation specific to complex Laravel + streaming scenarios. Octane added FrankenPHP support
  relatively recently in its own lifecycle.
- **Architecture:** Genuinely distinctive — because it's built on Caddy, FrankenPHP can replace
  the web server entirely (HTTP/2 and HTTP/3 support, automatic HTTPS certificate management),
  potentially removing the need for a separate Nginx layer in front of it. It also supports a
  "classic mode" (traditional one-request-per-boot, for compatibility) alongside its persistent
  "worker mode," allowing an incremental adoption path.
- **Streaming support:** Conceptually capable, but with materially less real-world validation in
  this specific combination (Laravel + Octane + streaming SSE) than Swoole has. The operational
  upside (dropping Nginx) is real but orthogonal to the concurrency problem this report is
  solving, and comes with the added risk of adopting the least-proven runtime of the three for the
  most concurrency-critical part of the application.

---

## 4. Comparative Analysis

| Criterion | Swoole (Octane) | RoadRunner (Octane) | FrankenPHP |
|---|---|---|---|
| Maturity / production track record | Highest — longest history, widest adoption with Octane | High — established, but second-choice to Swoole in most Octane guidance | Lowest — youngest project, fewest large-scale references |
| Build complexity | C extension compile step (PECL); needs a few extra Alpine packages | No C extension — single Go binary, simplest build | No C extension — single Go binary |
| Runtime architecture | In-process PHP extension, coroutine-capable | Separate Go process orchestrating PHP workers via RPC | PHP interpreter embedded directly in a Caddy (Go) binary |
| Streaming/SSE support | Proven, low-friction — works with standard echo/flush streaming code | Requires explicit `Generator`/`string` return-type declarations on every streaming closure, first- and third-party | Conceptually supported; least real-world validation of the three |
| Infra footprint change | None — drops in behind the existing Nginx sidecar pattern unchanged | None — same | Could eliminate the Nginx sidecar (built-in HTTP/2, HTTP/3, auto-HTTPS) — a real but separate benefit |
| Community / troubleshooting depth | Deepest, specifically for Laravel Octane | Solid, but a smaller pool of Octane-specific troubleshooting than Swoole | Smallest — a materially thinner base of prior art to draw on when something breaks |
| Risk for this project's specific stack | Low — matches the streaming pattern the app already uses | Medium — would require auditing/patching third-party streaming code (`laravel/ai`) for a return-type requirement before it could be trusted | Medium-high — newest runtime carrying the most concurrency-critical, least-forgiving part of the app |

---

## 5. Decision Rationale

Three factors drove the recommendation, in order of weight:

1. **Streaming compatibility is the whole point of this migration.** The problem being solved is
   specifically about long-lived SSE connections. A runtime that requires auditing and possibly
   modifying every streaming code path — including inside a third-party SDK not fully under this
   project's control — introduces exactly the kind of hidden failure mode (a stream that silently
   returns zero bytes) that would be expensive to discover in production rather than in testing.
   Swoole's output-buffering-based capture mechanism has no equivalent hidden precondition for
   standard streaming code.

2. **Maturity reduces risk on the highest-stakes code path.** These two services carry the app's
   core, revenue-adjacent feature (paid AI usage) and touch real wallet balances. This is not the
   part of the system to pair with the least-proven runtime option, however appealing FrankenPHP's
   other benefits (HTTP/3, dropping Nginx) may be for a future, lower-stakes evaluation.

3. **Lowest blast radius.** Swoole drops in behind the existing Nginx-sidecar-per-service pattern
   with no topology change required elsewhere in the system — no other service needs to know
   anything changed. RoadRunner would share this property but carries the streaming-closure risk
   above; FrankenPHP's infra-simplification benefit is real but represents a larger, separate
   architectural change better evaluated on its own merits later, not bundled into this decision.

**FrankenPHP is not rejected outright** — its ability to consolidate the runtime and web server
into one process is a genuinely interesting simplification worth revisiting once it has more
production track record, and once the current concurrency problem is already solved by a proven
option. It is deliberately not the first move.

**RoadRunner remains a credible fallback** if the Swoole build step (C extension compilation)
becomes a real operational obstacle later (e.g. a hosting platform that cannot support custom PHP
extensions). Should that happen, the RoadRunner streaming-closure requirement would need to be
resolved first — either by contributing a fix upstream to `laravel/ai`, or by wrapping affected
streaming responses in explicitly-typed generator closures at the application layer.

---

## 6. Recommendation

Adopt **Laravel Octane with the Swoole driver** for `ai-gateway-service` and `chat-service` only.
Leave the remaining seven services on PHP-FPM — they have no long-lived connections and gain
nothing from a persistent-worker runtime, while a persistent-worker model does introduce a real
category of risk (state that would normally reset per-request under PHP-FPM can instead leak
across requests on the same worker) that is only worth taking on where there's an actual payoff.

### Follow-up items for implementation planning (not covered by this report)
- An audit of application code in both target services for state that assumes a fresh boot per
  request (singleton bindings, static properties, anything cached on a long-lived object) — this
  class of bug is the primary risk of adopting any persistent-worker runtime, Swoole included.
- Worker/task-worker count tuning — deferred until real traffic data exists to tune against, rather
  than guessed in advance.
- Re-evaluate FrankenPHP as a possible Swoole *replacement* (not stacked on top of Octane, since
  Octane itself can run on FrankenPHP as an alternative driver) once it has more production
  maturity, specifically if dropping the per-service Nginx sidecar becomes a priority.
