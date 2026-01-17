<!DOCTYPE html>
<html>
<head>
    <title>Verification Code</title>
</head>
<body style="font-family: Arial, sans-serif; padding: 20px; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; background: #f9f9f9; padding: 20px; border-radius: 10px;">
        <h2 style="color: #2E37A4; text-align: center;">Idibia Verification</h2>
        <p>Hello,</p>
        <p>Your verification code is:</p>
        <div style="font-size: 32px; font-weight: bold; text-align: center; margin: 20px 0; letter-spacing: 5px; color: #0077B6;">
            {{ $otp }}
        </div>
        <p>This code will expire in 20 minutes.</p>
        <p>If you did not request this, please ignore this email.</p>
    </div>
</body>
</html>
