<?php

namespace App\Translation;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;

class PhpJsonFileLoader extends FileLoader
{
    /**
     * Create a new file loader instance.
     */
    public function __construct(Filesystem $files, array|string $path)
    {
        parent::__construct($files, $path);
    }

    /**
     * Load a locale from the given JSON file path.
     */
    protected function loadJsonPaths($locale): array
    {
        $output = parent::loadJsonPaths($locale);

        foreach ($this->paths as $path) {
            $file = rtrim($path, '/\\').DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.'app.php';

            if (! $this->files->exists($file)) {
                continue;
            }

            $lines = $this->files->getRequire($file);

            if (! is_array($lines) || $lines === []) {
                continue;
            }

            foreach ($lines as $key => $value) {
                if (! is_string($key) || $key === '') {
                    continue;
                }

                if (! is_string($value) || $value === '') {
                    continue;
                }

                $output[$key] = $value;
            }
        }

        return $output;
    }
}
