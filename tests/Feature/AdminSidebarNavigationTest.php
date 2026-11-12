<?php

namespace Tests\Feature;

use Tests\TestCase;

final class AdminSidebarNavigationTest extends TestCase
{
    public function test_admin_sidebar_prioritizes_daily_modules_on_desktop_and_mobile(): void
    {
        $html=file_get_contents(resource_path('views/admin/layout.blade.php'));
        $this->assertIsString($html);
        $order=[
            'dashboard','search','jobs','clients','quotes','invoices',
            'documents','deposits','addenda','expenses','labour','job-media',
            'reports','acceptances','signatures','equipment','reminders',
            'notifications','companies','users','billing','support','audit',
        ];
        foreach([
            '<nav class="side-nav" aria-label="Administration">',
            '<nav aria-label="Mobile administration">',
        ] as $opening){
            $start=strpos($html,$opening);
            $this->assertNotFalse($start);
            $end=strpos($html,'</nav>',$start);
            $this->assertNotFalse($end);
            $nav=substr($html,$start,$end-$start);
            $this->assertSame(count($order),substr_count($nav,'<a '));
            $offset=-1;
            foreach($order as $key){
                $found=strpos($nav,'href="{{ route(\'admin.'.$key);
                $this->assertNotFalse($found, 'Missing '.$key.' in '.$opening);
                $this->assertGreaterThan($offset,$found,
                    $key.' should appear in the intended order.');
                $offset=$found;
            }
            $this->assertStringNotContainsString(' Management',$nav);
            $this->assertStringContainsString('Documents',$nav);
            $this->assertStringContainsString('Invoices',$nav);
        }
    }
}
