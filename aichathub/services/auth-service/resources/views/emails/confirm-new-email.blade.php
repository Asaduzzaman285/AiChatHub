@extends('emails.layout')

@section('heading', 'Confirm your new email address')

@section('body')
<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#374151;">Hi {{ $name }},</p>
<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#374151;">You recently requested to change the email address associated with your Alveta.ai account to:</p>
<p style="margin:0 0 16px;font-size:14px;line-height:1.6;font-weight:600;color:#111827;">{{ $newEmail }}</p>
<p style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#374151;">Please click the button below to confirm this new email address:</p>
@endsection

@section('buttonLabel', 'Confirm New Email')

@section('footerNotes')
<p style="margin:20px 0 0;font-size:13px;line-height:1.6;color:#6B7280;">This confirmation link will expire in 24 hours.</p>
<p style="margin:8px 0 0;font-size:13px;line-height:1.6;color:#6B7280;">If you didn't request this change, no action is required. Your current sign-in email will remain unchanged.</p>
@endsection
