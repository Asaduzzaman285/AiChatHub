<div style="background:#F3F4F6;padding:40px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#FFFFFF;border-radius:12px;overflow:hidden;border:1px solid #E5E7EB;">
    <tr>
      <td style="padding:32px 40px 8px;">
        <span style="font-size:18px;font-weight:700;color:#111827;letter-spacing:-0.01em;">Alveta.ai</span>
      </td>
    </tr>
    <tr>
      <td style="padding:16px 40px 0;">
        <h2 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#111827;">@yield('heading')</h2>
        @yield('body')
      </td>
    </tr>
    <tr>
      <td style="padding:8px 40px 24px;" align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px auto;">
          <tr>
            <td align="center" style="border-radius:8px;background:#4F46E5;">
              <a href="{{ $verifyUrl }}" style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:600;color:#FFFFFF;text-decoration:none;border-radius:8px;">@yield('buttonLabel')</a>
            </td>
          </tr>
        </table>
      </td>
    </tr>
    <tr>
      <td style="padding:0 40px 24px;">
        <p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:#6B7280;">If the button doesn't work, copy and paste this link into your browser:</p>
        <p style="margin:0;font-size:12px;line-height:1.6;word-break:break-all;"><a href="{{ $verifyUrl }}" style="color:#4F46E5;">{{ $verifyUrl }}</a></p>
      </td>
    </tr>
    <tr>
      <td style="padding:0 40px 32px;border-top:1px solid #F3F4F6;">
        @yield('footerNotes')
      </td>
    </tr>
    <tr>
      <td style="padding:20px 40px;background:#F9FAFB;">
        <p style="margin:0;font-size:13px;color:#6B7280;">Thanks,<br>The Alveta.ai Team</p>
      </td>
    </tr>
  </table>
</div>
