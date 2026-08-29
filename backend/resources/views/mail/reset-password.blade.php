@extends('mail.layout')

@section('content')
    <p style="margin:0 0 12px;font-size:16px;">Hello {{ $user->name }},</p>
    <p style="margin:0 0 16px;line-height:1.6;">You requested a password reset for your <strong>AIMS</strong> account.</p>

    <p style="margin:0 0 20px;">
        <a href="{{ $resetUrl }}" style="display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:700;">
            Reset password
        </a>
    </p>

    <p style="margin:0;color:#64748b;font-size:13px;line-height:1.5;">
        This link expires in {{ $expiresMinutes }} minutes.<br>
        If the button does not work, copy this link:<br>
        <a href="{{ $resetUrl }}" style="color:#0f766e;word-break:break-all;">{{ $resetUrl }}</a>
    </p>
@endsection
