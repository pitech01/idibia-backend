<!DOCTYPE html>
<html>
<head>
    <title>Application Approved</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 5px;">
        <h2 style="color: #10b981;">Congratulations! You're Approved.</h2>
        <p>Dear Dr. {{ $user->name }},</p>
        <p>We are thrilled to inform you that your application to join IDIBIA has been <strong>approved</strong>!</p>
        <p>You can now log in to your dashboard to set up your schedule, view patients, and start your practice.</p>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ config('app.frontend_url') }}/login" style="background-color: #2563eb; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold;">Go to Dashboard</a>
        </div>
        <p>If you have any questions, feel free to contact our support team.</p>
        <br>
        <p>Welcome aboard,</p>
        <p><strong>The IDIBIA Team</strong></p>
    </div>
</body>
</html>
