<?php

namespace Modules\Core\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider as NwidartModuleServiceProvider;

/**
 * Base provider for every module in this project.
 *
 * It exists to fix one thing: nwidart registers a module's own lang/ directory
 * with loadTranslationsFrom($path) and no namespace argument, so keys like
 * `orders::errors.not_editable` never resolve and come back as the raw key.
 * Only its published-override branch passes a namespace. We keep translations
 * inside the module, so we register the namespace ourselves.
 *
 * Paired with `auto-discover.translations => false` in config/modules.php —
 * with both on, the unnamespaced registration wins and the fix does nothing.
 *
 * Every module provider must extend this rather than nwidart's. The generator
 * stub points at the wrong one, so it is the first edit after module:make.
 */
abstract class ModuleServiceProvider extends NwidartModuleServiceProvider
{
    protected function registerTranslations(): void
    {
        $overridePath = resource_path('lang/modules/'.$this->nameLower);

        $path = is_dir($overridePath)
            ? $overridePath
            : module_path($this->name, config('modules.paths.generator.lang.path', 'lang'));

        if (! is_dir($path)) {
            return;
        }

        $this->loadTranslationsFrom($path, $this->nameLower);
        $this->loadJsonTranslationsFrom($path);
    }
}
