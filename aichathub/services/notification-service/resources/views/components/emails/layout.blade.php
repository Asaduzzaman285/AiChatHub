<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
          <tr>
            <td style="background-color:#111827; padding:24px 32px;">
              <span style="color:#ffffff; font-size:18px; font-weight:700; letter-spacing:-0.02em;">✨ AI ChatHub</span>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;">
              {{ $slot }}
            </td>
          </tr>
          <tr>
            <td style="padding:20px 32px; background-color:#fafafa; border-top:1px solid #f0f0f0;">
              <p style="margin:0; font-size:12px; color:#9ca3af;">AI ChatHub &middot; This is an automated message, please don't reply directly.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
