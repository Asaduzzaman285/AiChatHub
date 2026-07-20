<x-emails.layout>
    <h1 style="margin:0 0 16px; font-size:20px; color:#111827;">Receipt</h1>
    <p style="margin:0 0 20px; font-size:14px; line-height:1.6; color:#4b5563;">
        Hi {{ $name }}, here's your receipt for the payment below.
    </p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb; border-radius:8px; margin-bottom:20px;">
        <tr>
            <td style="padding:16px;">
                <p style="margin:0 0 4px; font-size:13px; color:#9ca3af;">{{ $description }}</p>
                <p style="margin:0; font-size:24px; font-weight:700; color:#111827;">{{ $currency }} {{ $amount }}</p>
            </td>
        </tr>
    </table>
    <p style="margin:0; font-size:14px; line-height:1.6; color:#4b5563;">
        You can view all your invoices and receipts anytime from the Billing page.
    </p>
</x-emails.layout>
