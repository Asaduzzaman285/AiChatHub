<x-emails.layout>
    <h1 style="margin:0 0 16px; font-size:20px; color:#111827;">
        {{ $critical ? '⚠️ Balance critically low' : 'Your balance is running low' }}
    </h1>
    <p style="margin:0 0 20px; font-size:14px; line-height:1.6; color:#4b5563;">
        Hi {{ $name }}, your wallet balance is currently
        <strong style="color:#111827;">${{ $balance }}</strong>.
        @if($critical)
            Chat requests may start failing once your balance and credit buffer are used up.
        @else
            Consider topping up soon to avoid interruptions.
        @endif
    </p>
    <p style="margin:0; font-size:14px; line-height:1.6; color:#4b5563;">
        Top up anytime from the Wallet page.
    </p>
</x-emails.layout>
