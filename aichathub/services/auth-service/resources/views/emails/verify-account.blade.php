@extends('emails.layout')

@section('heading', 'Verify your Alveta.ai account')

@section('body')
<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#374151;">Hi {{ $name }},</p>
<p style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#374151;">Please click the button below to verify your email address and activate your Alveta.ai account.</p>
@endsection

@section('buttonLabel', 'Verify Email Address')

@section('footerNotes')
<p style="margin:20px 0 0;font-size:13px;line-height:1.6;color:#6B7280;">This verification link will expire in 24 hours.</p>
<p style="margin:8px 0 0;font-size:13px;line-height:1.6;color:#6B7280;">If you didn't create an account with Alveta.ai, you can safely ignore this email.</p>
@endsection
