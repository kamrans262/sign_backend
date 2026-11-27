<!doctype html>
<html lang="{{ \App\Support\AppLocale::current() }}"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:Arial,sans-serif;color:#122536;line-height:1.55">
<p>{{ \App\Support\MailText::tr('Hello') }} {{ $borrowerName }},</p>
<p>{{ \App\Support\MailText::tr('This is a reminder from') }} <strong>{{ $companyName }}</strong> {{ \App\Support\MailText::tr('about the equipment currently on loan:') }}</p>
<p><strong>{{ $equipmentName }}</strong> ({{ $equipmentCode }})</p>
<p>{{ \App\Support\MailText::tr('Expected return date:') }} <strong>{{ $expectedReturnDate }}</strong></p>
<p>{{ \App\Support\MailText::tr('If you have already returned it or need more time, please contact the lending company directly.') }}</p>
<p>{{ \App\Support\MailText::tr('Sign2Digital equipment lending records') }}</p>
</body></html>
