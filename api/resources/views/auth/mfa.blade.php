<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verification code</title>
</head>
<body>
    <main>
        <h1>Enter your verification code</h1>
        <form method="POST" action="{{ route('login.mfa') }}">
            @csrf
            <p>
                <label for="code">Code from your authenticator app</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus>
            </p>
            @error('code')
                <p role="alert">{{ $message }}</p>
            @enderror
            <button type="submit">Verify</button>
        </form>
    </main>
</body>
</html>
