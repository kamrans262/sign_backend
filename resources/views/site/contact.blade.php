<p>Use our <a href="{{ route('site.contact') }}">Contact Us form</a> to send a message without signing in.</p>
@if(config('site.privacy_email'))<p>Contact: <a href="mailto:{{ config('site.privacy_email') }}">{{ config('site.privacy_email') }}</a></p>@endif
@if(config('site.operator_address'))<p>Operator address: {{ config('site.operator_address') }}</p>@endif
