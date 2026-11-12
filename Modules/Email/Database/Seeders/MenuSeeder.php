<?php

namespace Modules\Email\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

final class MenuSeeder extends Seeder
{
    public int $seedOrder = 86;

    public function run(): void
    {
        MenuService::item('ho', 'setup', [
            'path' => 'email-templates', 'label' => 'Email templates', 'route' => 'app.setup.email-templates.index',
            'permission' => 'email.templates.view', 'sort' => 90,
        ]);
    }
}
