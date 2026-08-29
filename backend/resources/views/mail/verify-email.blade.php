@extends('mail.layout')

@section('content')
    <p style="margin:0 0 12px;font-size:16px;">Hello {{ $user->name }},</p>
    <p style="margin:0 0 16px;line-height:1.6;">Verify your email address to activate your <strong>AIMS</strong> account.</p>

    <div style="background:#f0fdfa;border:1px solid #99f6e4;border-radius:10px;padding:16px;text-align:center;margin:0 0 20px;">
        <div style="font-size:12px;color:#0f766e;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;">Verification code</div>
        <div style="font-size:32px;font-weight:700;letter-spacing:0.2em;color:#0f172a;margin-top:8px;">{{ $code }}</div>
    </div>

    <p style="margin:0 0 12px;line-height:1.6;">Or click the button below:</p>
    <p style="margin:0 0 20px;">
        <a href="{{ $verifyUrl }}" style="display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:700;">
            Verify email
        </a>
    </p>

    <p style="margin:0;color:#64748b;font-size:13px;line-height:1.5;">
        This code expires in {{ $expiresHours }} hours.<br>
        Link: <a href="{{ $verifyUrl }}" style="color:#0f766e;">{{ $verifyUrl }}</a>
    </p>
@endsection
