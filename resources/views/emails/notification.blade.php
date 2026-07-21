<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Notification' }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #f9f9f9; padding: 30px; border-radius: 10px; }
        .header { text-align: center; margin-bottom: 30px; }
        .logo { color: #667eea; font-size: 24px; font-weight: bold; }
        .greeting { font-size: 18px; font-weight: bold; color: #333; margin-bottom: 20px; }
        .message { font-size: 16px; color: #666; line-height: 1.8; margin-bottom: 25px; }
        .button { display: inline-block; padding: 12px 30px; background: #667eea; color: white; 
                 text-decoration: none; border-radius: 5px; font-weight: bold; }
        .salutation { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #888; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #999; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">{{ config('app.name', 'Flink') }}</div>
        </div>
        
        @if(!empty($greeting))
        <div class="greeting">
            {{ $greeting }}
        </div>
        @endif
        
        <div class="message">
            {!! nl2br(e($message)) !!}
        </div>
        
        @if(!empty($link))
        <div style="text-align: center; margin: 25px 0;">
            <a href="{{ $link }}" class="button" target="_blank">
                {{ $actionText }}
            </a>
        </div>
        @endif
        
        @if(!empty($salutation))
        <div class="salutation">
            {{ $salutation }}
        </div>
        @endif
        
        <div class="footer">
            <p>© {{ date('Y') }} {{ config('app.name', 'Flink') }}. Tous droits réservés.</p>
            <p>Cet email a été envoyé à {{ $email }}.</p>
            @if(!empty($unsubscribeLink))
            <p><a href="{{ $unsubscribeLink }}">Se désabonner</a></p>
            @endif
        </div>
    </div>
</body>
</html>