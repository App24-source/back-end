<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title></title>
    <style type="text/css" rel="stylesheet" media="all">
        body {
            width: 100% !important;
            height: 100%;
            margin: 0;
            -webkit-text-size-adjust: none;
            background-color: #F2F4F6;
            color: #51545E;
            font-family: "Nunito Sans", Helvetica, Arial, sans-serif;
        }
        a {
            color: #3869D4;
            text-decoration: none;
        }
        a img {
            border: none;
        }
        td {
            word-break: break-word;
        }
        h1, h2, h3 {
            margin-top: 0;
            color: #333333;
            font-weight: bold;
            text-align: left;
        }
        h1 { font-size: 22px; }
        h2 { font-size: 16px; }
        h3 { font-size: 14px; }
        p {
            margin: .4em 0 1.1875em;
            font-size: 16px;
            line-height: 1.625;
        }
        .button {
            background-color: #1F94CA;
            border: 10px solid #1F94CA;
            display: inline-block;
            color: #FFF;
            text-decoration: none;
            border-radius: 3px;
            box-shadow: 0 2px 3px rgba(0, 0, 0, 0.16);
            -webkit-text-size-adjust: none;
            box-sizing: border-box;
        }
        .body-action {
            width: 100%;
            margin: 30px auto;
            padding: 0;
            text-align: center;
        }
        .content-cell {
            padding: 30px;
        }
        .email-wrapper {
            width: 100%;
            margin: 0;
            padding: 0;
            background-color: #F2F4F6;
        }
        .email-content {
            width: 100%;
            margin: 0;
            padding: 0;
        }
        .email-body_inner {
            width: 570px;
            margin: 0 auto;
            padding: 0;
            background-color: #FFFFFF;
        }
        .email-footer {
            width: 570px;
            margin: 0 auto;
            padding: 0;
            text-align: center;
        }
        @media only screen and (max-width: 600px) {
            .email-body_inner,
            .email-footer {
                width: 100% !important;
            }
        }
    </style>
</head>
<body>
    <table class="email-wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
        <tr>
            <td align="center">
                <table class="email-content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
                    <tr>
                        <td style="padding: 25px 0;text-align: center;">
                            <a href="http://www.v3.app24.ma">
                                <img src="{{asset('https://v3.app24.ma/logo_2.svg')}}" height="35" width="190" alt="App24">
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-body" width="570" cellpadding="0" cellspacing="0">
                            <table class="email-body_inner" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
                                <tr>
                                    <td class="content-cell">
                                        <div class="f-fallback">
                                            <h1>Bonjour, <span style="text-transform: uppercase">{{ $nom }}</span>!</h1>
                                            <p>Veuillez cliquer sur le bouton ci-dessous pour vérifier votre adresse e-mail.</p>
                                            <table class="body-action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
                                                <tr>
                                                    <td align="center">
                                                        <table width="100%" border="0" cellspacing="0" cellpadding="0" role="presentation">
                                                            <tr>
                                                                <td align="center">
                                                                    {{-- <a href="{{ $url }}" class="button">Vérifier l'adresse e-mail</a> --}}
                                                                    <p>Code de vérification :  <strong>{{ $code }}</strong></p>
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            </table>
                                            <p>Si vous n'avez pas créé de compte, aucune autre action n'est requise.</p>
                                            <p>Merci,<br>{{ config('app.name') }}</p>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <table class="email-footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
                                <tr>
                                    <td class="content-cell" align="center">
                                        <p class="f-fallback sub align-center">
                                            <span style="color: #2E9AA7; font-weight: bold;">Alx <img src="https://app24.ma/assets/images/heart.png" width="7px" alt=""></span><br>
                                            Casablanca, Sidi Maarouf, Florida.<br>
                                            Emeuble 7, Bureau 48
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
