<!doctype html>
<html lang="en">
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5;">
    <p>Hello,</p>
    <p>You have been invited to join <strong>{{ $orgName }}</strong> on Success Meter as {{ $role === 'owner' ? 'an owner' : 'a '.$role }}.</p>
    <p><a href="{{ $link }}" style="display: inline-block; padding: 10px 16px; background: #6366f1; color: #ffffff; text-decoration: none; border-radius: 6px;">Accept the invitation</a></p>
    <p>Or open this link: <br><a href="{{ $link }}">{{ $link }}</a></p>
    <p>The invitation expires on {{ $expiresOn }}. If you weren't expecting it, you can ignore this email.</p>
    <p>Success Meter</p>
</body>
</html>
