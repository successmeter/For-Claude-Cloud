<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in</title>
</head>
<body>
    <main>
        <h1>Sign in</h1>
        <form method="POST" action="{{ route('login') }}">
            @csrf
            <p>
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            </p>
            <p>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </p>
            @error('email')
                <p role="alert">{{ $message }}</p>
            @enderror
            <button type="submit">Sign in</button>
        </form>
    </main>
</body>
</html>
