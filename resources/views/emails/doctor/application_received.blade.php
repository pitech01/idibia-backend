<!DOCTYPE html>
<html>
<head>
    <title>Application Received</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 5px;">
        <h2 style="color: #2563eb;">We've Received Your Application!</h2>
        <p>Dear Dr. {{ $user->name }},</p>
        <p>Thank you for registering with IDIBIA. We have successfully received your application details and documents.</p>
        <p>Our team is currently reviewing your profile to ensure all credentials meet our standards. This process usually takes 24-48 hours.</p>
        <p>You will receive another email once your application has been processed.</p>
        <br>
        <p>Best Regards,</p>
        <p><strong>The IDIBIA Team</strong></p>
    </div>
</body>
</html>
