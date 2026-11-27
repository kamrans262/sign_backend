<?php

namespace Tests\Feature;

use App\Support\AppLocale;
use App\Support\PublicPageText;
use Illuminate\Http\Request;
use Tests\TestCase;

final class Stage3LocalizationTest extends TestCase
{
    public function test_language_selection_supports_four_locales_and_english_fallback(): void
    {
        foreach ([
            'English'=>'en','French'=>'fr','German'=>'de','Italian'=>'it',
            'fr-CH'=>'fr','de_CH'=>'de','it-IT'=>'it','es'=>'en',''=>'en',
        ] as $name=>$code) {
            $this->assertSame($code, AppLocale::normalize($name));
        }
        $request=Request::create('/api/v1/jobs','GET',[],[],[],
            ['HTTP_X_APP_LANGUAGE'=>'fr','HTTP_ACCEPT_LANGUAGE'=>'de-CH,de;q=0.9']);
        $this->assertSame('fr', AppLocale::forRequest($request));
        $link=Request::create('/sign/opaque?lang=it','GET',[],[],[],
            ['HTTP_X_APP_LANGUAGE'=>'fr']);
        $this->assertSame('it', AppLocale::forRequest($link));
        $this->assertSame('de', AppLocale::forRequest(
            Request::create('/quote-view/opaque','GET',[],[],[],
                ['HTTP_ACCEPT_LANGUAGE'=>'es;q=1,de-CH;q=0.8'])));
    }

    public function test_request_locale_is_restored_after_a_request(): void
    {
        $before=app()->getLocale();
        try {
            app()->setLocale('de');
            $this->assertSame('Rechnung',AppLocale::text('Invoice'));
            $this->assertSame('INV-001',AppLocale::text('INV-001'));
            $this->assertSame('Baustellenbericht',AppLocale::text('Site Report'));
            $this->assertSame('Rechnung',PublicPageText::tr('Invoice'));
            app()->setLocale('fr');
            $this->assertSame('Devis',PublicPageText::tr('Quote'));
        } finally {
            app()->setLocale($before);
        }
    }

    public function test_document_and_email_templates_preserve_ids_emails_links_and_user_content(): void
    {
        $link='https://example.test/quote-view/123?signature=abc%2B&expires=25';
        $message='Keep {number} and CHF 1,000 exactly unchanged.';
        foreach (AppLocale::SUPPORTED as $locale) {
            $body=AppLocale::email('quote_body',[
                'number'=>'Q-001','message'=>$message,'url'=>$link,
            ],$locale);
            $this->assertStringContainsString($link,$body);
            $this->assertStringContainsString($message,$body);
            $this->assertStringContainsString('Q-001',$body);
            $this->assertStringContainsString("\n\n",$body);
            $this->assertStringNotContainsString('\\n',$body);
            $this->assertStringContainsString('Invoice',AppLocale::email(
                'invoice_body',['number'=>'Invoice Q','url'=>$link], 'en'));
            $guide=AppLocale::aiInstruction($locale);
            $this->assertStringContainsString('preserve JSON field names',$guide);
        }
    }

    public function test_public_labels_translate_without_affecting_tokenized_document_routes(): void
    {
        $before=app()->getLocale();
        try {
            foreach (['fr','de','it'] as $code) {
                app()->setLocale($code);
                $this->assertNotSame('Download Signed PDF',PublicPageText::tr('Download Signed PDF'));
                $this->assertSame('Q-002',PublicPageText::tr('Q-002'));
            }
        } finally {
            app()->setLocale($before);
        }
    }
}
