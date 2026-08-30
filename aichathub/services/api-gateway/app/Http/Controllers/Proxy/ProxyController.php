<?php

namespace App\Http\Controllers\Proxy;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProxyController extends Controller
{
    private array $serviceMap;

    public function __construct()
    {
        $this->serviceMap = [
            'auth'         => rtrim(config('services.auth_url'),         '/'),
            'subscription' => rtrim(config('services.subscription_url'), '/'),
            'wallet'       => rtrim(config('services.wallet_url'),       '/'),
            'payment'      => rtrim(config('services.payment_url'),      '/'),
            'ai_gateway'   => rtrim(config('services.ai_gateway_url'),   '/'),
            'chat'         => rtrim(config('services.chat_url'),         '/'),
            'billing'      => rtrim(config('services.billing_url'),      '/'),
        ];
    }

    public function proxyAuth(Request $request, string $path = '')          { return $this->forward($request, 'auth',         "api/v1/auth/{$path}"); }
    public function proxySubscription(Request $request, string $path = '')  { return $this->forward($request, 'subscription', "api/v1/{$request->segment(3)}/{$path}"); }
    public function proxyWallet(Request $request, string $path = '')        { return $this->forward($request, 'wallet',       "api/v1/wallet/{$path}"); }
    public function proxyPayment(Request $request, string $path = '')       { return $this->forward($request, 'payment',      "api/v1/{$request->segment(3)}/{$path}"); }
    public function proxyAiGateway(Request $request, string $path = '')     { return $this->forward($request, 'ai_gateway',   "api/v1/{$request->segment(3)}/{$path}"); }
    public function proxyChat(Request $request, string $path = '')          { return $this->forward($request, 'chat',         "api/v1/{$request->segment(3)}/{$path}"); }
    public function proxyBilling(Request $request, string $path = '')       { return $this->forward($request, 'billing',      "api/v1/{$request->segment(3)}/{$path}"); }

    private function forward(Request $request, string $service, string $path): HttpResponse
    {
        $baseUrl     = $this->serviceMap[$service];
        $targetUrl   = "{$baseUrl}/{$path}";
        $queryString = $request->getQueryString();
        if ($queryString) $targetUrl .= "?{$queryString}";

        $method  = strtolower($request->method());
        // content-type is dropped unconditionally, not just for file uploads: when
        // Http::attach() builds a fresh multipart body it generates its own boundary,
        // so forwarding the original client's Content-Type (with the OLD boundary)
        // makes the header and the actual body disagree — the receiving service's
        // multipart parser then silently finds no files at all. For the non-file
        // case, Laravel's HTTP client sets an appropriate Content-Type on its own
        // (application/json for an array body) so dropping it here is harmless.
        $headers = collect($request->headers->all())
            ->except(['host', 'content-length', 'content-type'])
            ->map(fn ($v) => $v[0])
            ->all();

        // Pass authenticated user ID downstream as a trusted header
        if ($request->user()) {
            $headers['X-Auth-User-Id']    = $request->user()->id;
            $headers['X-Auth-User-Email'] = $request->user()->email;
        }

        // Disable SSL verify for local docker networking. withoutRedirecting() matters
        // here too — Guzzle follows redirects by default, so without this, a real
        // 3xx from a downstream service (e.g. EmailVerificationController's redirect
        // into the frontend app) would get silently followed and swallowed by THIS
        // proxy itself: it would fetch the frontend page server-side and hand back
        // its raw HTML as the "API response" instead of relaying the redirect for the
        // browser to actually navigate. A generic proxy should always relay upstream's
        // real response, not decide to follow redirects on the caller's behalf.
        $http = Http::withHeaders($headers)->withoutVerifying()->withoutRedirecting();

        // Stream SSE responses (chat/stream endpoint) get a long timeout for the
        // whole streamed exchange; other routes still get more than Laravel's 30s
        // default — local Docker Desktop + WSL2 bind-mounted volumes make even a
        // single downstream request noticeably slower than a native filesystem.
        // 'stream' => true keeps Guzzle from buffering the whole upstream SSE
        // response before returning — without it, this proxy sat blocked (sending
        // zero bytes to its own nginx sidecar) for the AI's entire generation time,
        // which both defeated real-time streaming to the browser AND, once a
        // response ran past nginx's fastcgi_read_timeout, got the connection killed
        // mid-transfer (see gateway.conf's /api/v1/chat block for the other half of
        // that fix).
        $isStream = str_contains($path, 'stream') || str_contains($path, 'compare');
        $http = $isStream
            ? $http->timeout(300)->withOptions(['stream' => true])
            : $http->timeout(45);

        // $request->all() only carries text fields — an UploadedFile in there gets
        // silently dropped (Laravel's HTTP client has no idea it should become a
        // multipart part), so any downstream file upload just sees "file required"
        // with no obvious cause. Every endpoint proxied before now was JSON-only,
        // so this never surfaced until the chat attachment upload needed it.
        if ($request->allFiles()) {
            foreach ($request->allFiles() as $key => $file) {
                $http = $http->attach($key, file_get_contents($file->getRealPath()), $file->getClientOriginalName());
            }
            $response = $http->{$method}($targetUrl, $request->except(array_keys($request->allFiles())));
        } else {
            $response = $http->{$method}($targetUrl, $request->all());
        }

        return $isStream ? $this->streamResponse($response) : $this->bufferedResponse($response);
    }

    private function bufferedResponse(ClientResponse $response): HttpResponse
    {
        // Hop-by-hop headers describe the upstream connection/framing, not this
        // one — forwarding them verbatim (esp. Transfer-Encoding: chunked)
        // alongside a Content-Length Symfony recomputes for the already-decoded
        // body confuses nginx's response framing, which silently never flushes
        // any bytes to the client even though PHP-FPM completed (confirmed live
        // via a file upload that hung indefinitely client-side despite chat-service
        // logging a real 201).
        $responseHeaders = collect($response->headers())
            ->except(['transfer-encoding', 'content-encoding', 'content-length', 'connection', 'keep-alive'])
            ->all();

        return response(
            $response->body(),
            $response->status(),
            $responseHeaders
        );
    }

    // Relays the upstream SSE response chunk-by-chunk as it arrives, instead of
    // buffering the entire thing first — a real closure with a real echo/flush loop,
    // not a generator, matching the same pattern (and the same reasoning) used in
    // ai-gateway-service's own ChatController::stream() for the leg before this one.
    private function streamResponse(ClientResponse $response): StreamedResponse
    {
        $responseHeaders = collect($response->headers())
            ->except(['transfer-encoding', 'content-encoding', 'content-length', 'connection', 'keep-alive'])
            ->all();

        $body = $response->toPsrResponse()->getBody();

        return response()->stream(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(8192);
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            }
        }, $response->status(), $responseHeaders);
    }
}
