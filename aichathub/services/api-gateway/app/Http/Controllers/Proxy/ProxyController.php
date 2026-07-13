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
        $headers = collect($request->headers->all())
            ->except(['host', 'content-length'])
            ->map(fn ($v) => $v[0])
            ->all();

        // Pass authenticated user ID downstream as a trusted header
        if ($request->user()) {
            $headers['X-Auth-User-Id']    = $request->user()->id;
            $headers['X-Auth-User-Email'] = $request->user()->email;
        }

        // Disable SSL verify for local docker networking
        $http = Http::withHeaders($headers)->withoutVerifying();

        // Stream SSE responses (chat/stream endpoint)
        if (str_contains($path, 'stream') || str_contains($path, 'compare')) {
            $http = $http->timeout(120);
        }

        $response = $http->{$method}($targetUrl, $request->all());

        return response(
            $response->body(),
            $response->status(),
            $response->headers()
        );
    }
}
