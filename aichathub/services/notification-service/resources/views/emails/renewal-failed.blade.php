<x-emails.layout>
    <h1 style="margin:0 0 16px; font-size:20px; color:#111827;">We couldn't renew your subscription</h1>
    <p style="margin:0 0 20px; font-size:14px; line-height:1.6; color:#4b5563;">
        Hi {{ $name }}, we tried to renew your <strong style="color:#111827;">{{ $packageName }}</strong> plan
        but the payment didn't go through. Your access continues until the end of your current billing
        period, but please update your payment details soon to avoid losing access.
    </p>
    <p style="margin:0; font-size:14px; line-height:1.6; color:#4b5563;">
        You can review your plan and payment method from the Pricing page.
    </p>
</x-emails.layout>
