<?php

namespace App\Http\Controllers\Proxy;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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

    private function forward(Request $request, string $service, string $path): \Illuminate\Http\Response
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

        // Disable SSL verify for local docker networking
        $http = Http::withHeaders($headers)->withoutVerifying();

        // Stream SSE responses (chat/stream endpoint) get a long timeout for the
        // whole streamed exchange; other routes still get more than Laravel's 30s
        // default — local Docker Desktop + WSL2 bind-mounted volumes make even a
        // single downstream request noticeably slower than a native filesystem.
        $http = str_contains($path, 'stream') || str_contains($path, 'compare')
            ? $http->timeout(120)
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
}
