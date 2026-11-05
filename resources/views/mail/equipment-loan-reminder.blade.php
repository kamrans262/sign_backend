<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:Arial,sans-serif;color:#122536;line-height:1.55">
<p>Hello {{ $borrowerName }},</p>
<p>This is a reminder from <strong>{{ $companyName }}</strong> about the equipment currently on loan:</p>
<p><strong>{{ $equipmentName }}</strong> ({{ $equipmentCode }})</p>
<p>Expected return date: <strong>{{ $expectedReturnDate }}</strong></p>
<p>If you have already returned it or need more time, please contact the lending company directly.</p>
<p>Sign2Digital equipment lending records</p>
</body></html>
