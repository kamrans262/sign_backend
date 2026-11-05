<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class AdminUiConsistencyTest extends TestCase
{
    public function test_m20_admin_sidebar_has_one_link_per_module_in_desktop_and_mobile_navigation(): void
    {
        $layout = file_get_contents(resource_path('views/admin/layout.blade.php'));
        $this->assertIsString($layout);

        foreach ([
            'side-nav' => ['<nav class="side-nav"', '</nav>'],
            'mobile-menu' => ['<nav aria-label="Mobile administration">', '</nav>'],
        ] as $name => [$start, $end]) {
            $this->assertStringContainsString($start, $layout);
            $section = explode($end, explode($start, $layout, 2)[1], 2)[0];
            foreach ([
                "route('admin.equipment.index')",
                "route('admin.reminders.index')",
                "route('admin.notifications.index')",
            ] as $route) {
                $this->assertSame(1, substr_count($section, $route), $name.' duplicate/missing '.$route);
            }
        }
    }

    public function test_admin_actions_use_one_shared_confirmation_dialog_instead_of_native_confirms(): void
    {
        $layout = file_get_contents(resource_path('views/admin/layout.blade.php'));
        $this->assertStringContainsString('id="admin-confirm-dialog"', $layout);
        $this->assertStringContainsString('dialog.showModal()', $layout);
        $this->assertStringContainsString('requestSubmit(', $layout);
        $this->assertStringContainsString('aria-describedby="admin-confirm-message"', $layout);

        $count = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('views/admin'))
        );
        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php')) continue;
            $view = file_get_contents($file->getPathname());
            $this->assertIsString($view);
            $this->assertDoesNotMatchRegularExpression('/\b(?:window\.)?confirm\s*\(/', $view, $file->getFilename());
            $count += substr_count($view, 'data-confirm="');
        }
        $this->assertGreaterThanOrEqual(35, $count);
    }

    public function test_labour_filter_controls_share_responsive_grid_without_changing_generic_toolbars(): void
    {
        $labour = file_get_contents(resource_path('views/admin/labour/index.blade.php'));
        $css = file_get_contents(public_path('admin-assets/admin.css'));
        $this->assertStringContainsString('class="search admin-labour-filters"', $labour);
        $this->assertStringContainsString('class="admin-labour-filter-actions"', $labour);
        $this->assertStringContainsString('.hope-ui-adapt .admin-labour-filters', $css);
        $this->assertStringContainsString('.admin-confirm-dialog::backdrop', $css);
    }
}
