<?php

// php -d phar.readonly=0 build.php

declare(strict_types=1);

$pharFile = "YOUR_PLUGIN_NAME.phar";
$finalPharFile = __DIR__ . "/" . $pharFile;

$startTime = microtime(true);

function downloadGitHubRepository(
    string $url,
    string $branch,
    string $destination
): void {
    $url = trim($url);
    $url = rtrim($url, '/');

    if (!preg_match(
        '~^https?://github\.com/([^/]+)/([^/]+)(?:/.*)?$~',
        $url,
        $matches
    )) {
        throw new RuntimeException(
            "Invalid GitHub repository URL: $url"
        );
    }

    $owner = $matches[1];

    $repository = preg_replace(
        '/\.git$/',
        '',
        $matches[2]
    );

    if ($repository === null || $repository === '') {
        throw new RuntimeException(
            "Invalid GitHub repository name: $url"
        );
    }

    $branch = trim($branch);

    if ($branch === '') {
        throw new RuntimeException(
            "Branch cannot be empty for: $url"
        );
    }

    $zipUrl = sprintf(
        'https://codeload.github.com/%s/%s/zip/refs/heads/%s',
        rawurlencode($owner),
        rawurlencode($repository),
        rawurlencode($branch)
    );

    echo "Downloading: {$owner}/{$repository}\n";
    echo "Branch: {$branch}\n";

    $zipFile = $destination . '/repository.zip';

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: CurrencyAPI-Build\r\n",
            'timeout' => 60
        ]
    ]);

    $data = @file_get_contents(
        $zipUrl,
        false,
        $context
    );

    if ($data === false) {
        throw new RuntimeException(
            "Could not download repository branch '{$branch}': $url"
        );
    }

    if (file_put_contents($zipFile, $data) === false) {
        throw new RuntimeException(
            "Could not save repository ZIP."
        );
    }

    $zip = new ZipArchive();

    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException(
            "Could not open downloaded ZIP: $url"
        );
    }

    $extractDir = $destination . '/repository';

    if (
        !is_dir($extractDir) &&
        !mkdir($extractDir, 0777, true)
    ) {
        $zip->close();

        throw new RuntimeException(
            "Could not create extraction directory."
        );
    }

    if (!$zip->extractTo($extractDir)) {
        $zip->close();

        throw new RuntimeException(
            "Could not extract repository: $url"
        );
    }

    $zip->close();

    @unlink($zipFile);

    echo "Downloaded successfully.\n";
}

function findRepositoryRoot(string $directory): ?string {
    if (!is_dir($directory)) {
        return null;
    }

    $items = scandir($directory);

    if ($items === false) {
        return null;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . '/' . $item;

        if (is_dir($path)) {
            return $path;
        }
    }

    return null;
}

function readDependencies(string $file): array {
    if (!file_exists($file)) {
        return [];
    }

    $lines = file(
        $file,
        FILE_IGNORE_NEW_LINES
    );

    if ($lines === false) {
        throw new RuntimeException(
            "Could not read dependencies.yml"
        );
    }

    $dependencies = [];
    $insideList = false;
    $currentDependency = null;

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        if (str_starts_with($line, '#')) {
            continue;
        }

        if ($line === 'dependency:') {
            $insideList = true;
            continue;
        }

        if (!$insideList) {
            continue;
        }

        if (
            preg_match(
                '/^-\s*url:\s*(.+)$/',
                $line,
                $matches
            )
        ) {
            if ($currentDependency !== null) {
                if (
                    !isset($currentDependency['url']) ||
                    !isset($currentDependency['branch']) ||
                    !isset($currentDependency['version'])
                ) {
                    throw new RuntimeException(
                        "Each dependency must contain 'url', 'branch', and 'version'."
                    );
                }

                $dependencies[] = $currentDependency;
            }

            $url = trim(
                $matches[1],
                "\"'"
            );

            $currentDependency = [
                'url' => $url,
                'branch' => null,
                'version' => null
            ];

            continue;
        }

        if (
            preg_match(
                '/^branch:\s*(.+)$/',
                $line,
                $matches
            )
        ) {
            if ($currentDependency === null) {
                throw new RuntimeException(
                    "'branch' found before a dependency URL."
                );
            }

            $branch = trim(
                $matches[1],
                "\"'"
            );

            $currentDependency['branch'] = $branch;

            continue;
        }

        if (
            preg_match(
                '/^version:\s*(.+)$/',
                $line,
                $matches
            )
        ) {
            if ($currentDependency === null) {
                throw new RuntimeException(
                    "'version' found before a dependency URL."
                );
            }

            $version = trim(
                $matches[1],
                "\"'"
            );

            $currentDependency['version'] = $version;

            continue;
        }
    }

    if ($currentDependency !== null) {
        if (
            !isset($currentDependency['url']) ||
            !isset($currentDependency['branch']) ||
            !isset($currentDependency['version'])
        ) {
            throw new RuntimeException(
                "Each dependency must contain 'url', 'branch', and 'version'."
            );
        }

        $dependencies[] = $currentDependency;
    }

    foreach ($dependencies as $dependency) {
        if (
            !isset($dependency['url']) ||
            !isset($dependency['branch']) ||
            !isset($dependency['version'])
        ) {
            throw new RuntimeException(
                "Invalid dependency configuration."
            );
        }

        if ($dependency['url'] === '') {
            throw new RuntimeException(
                "Dependency URL cannot be empty."
            );
        }

        if ($dependency['branch'] === '') {
            throw new RuntimeException(
                "Dependency branch cannot be empty."
            );
        }

        if ($dependency['version'] === '') {
            throw new RuntimeException(
                "Dependency version cannot be empty."
            );
        }
    }

    return $dependencies;
}

function readVirionVersion(string $file): string {
    if (!file_exists($file)) {
        throw new RuntimeException(
            "virion.yml not found: $file"
        );
    }

    $lines = file(
        $file,
        FILE_IGNORE_NEW_LINES
    );

    if ($lines === false) {
        throw new RuntimeException(
            "Could not read virion.yml: $file"
        );
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        if (str_starts_with($line, '#')) {
            continue;
        }

        if (
            preg_match(
                '/^version:\s*(.+)$/',
                $line,
                $matches
            )
        ) {
            $version = trim(
                $matches[1],
                "\"'"
            );

            if ($version === '') {
                throw new RuntimeException(
                    "version in virion.yml cannot be empty: $file"
                );
            }

            return $version;
        }
    }

    throw new RuntimeException(
        "No version found in virion.yml: $file"
    );
}

function addDirectoryToPhar(
    Phar $phar,
    string $directory,
    string $pharPrefix,
    int &$fileCount
): void {
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $directory,
            RecursiveDirectoryIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $realPath = $file->getRealPath();

        if ($realPath === false) {
            continue;
        }

        $relativePath = substr(
            $realPath,
            strlen($directory) + 1
        );

        $relativePath = str_replace(
            '\\',
            '/',
            $relativePath
        );

        $pharPath =
            rtrim($pharPrefix, '/') .
            '/' .
            $relativePath;

        if (isset($phar[$pharPath])) {
            throw new RuntimeException(
                "File conflict detected: $pharPath"
            );
        }

        $contents = file_get_contents($realPath);

        if ($contents === false) {
            throw new RuntimeException(
                "Could not read file: $realPath"
            );
        }

        $phar->addFromString(
            $pharPath,
            $contents
        );

        echo "Added: $pharPath\n";

        $fileCount++;
    }
}

function deleteDirectory(string $directory): void {
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);

    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . '/' . $item;

        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($directory);
}

if (file_exists($finalPharFile)) {
    @unlink($finalPharFile);

    clearstatcache();

    echo "Removed old $pharFile\n\n";
}

$tempDirectory = null;

try {
    $dependenciesFile =
        __DIR__ . '/dependencies.yml';

    $dependencies = readDependencies(
        $dependenciesFile
    );

    if (file_exists($dependenciesFile)) {
        echo "Found dependencies.yml\n";

        if (count($dependencies) === 0) {
            echo "No dependencies listed.\n\n";
        } else {
            echo "Found "
                . count($dependencies)
                . " dependencies:\n";

            foreach ($dependencies as $dependency) {
                echo "  - "
                    . $dependency['url']
                    . " @ "
                    . $dependency['branch']
                    . " (version "
                    . $dependency['version']
                    . ")\n";
            }

            echo "\n";
        }
    } else {
        echo "No dependencies.yml found.\n";
        echo "Skipping dependencies.\n\n";
    }

    $tempDirectory =
        sys_get_temp_dir()
        . '/currencyapi-build-'
        . bin2hex(random_bytes(8));

    if (!mkdir($tempDirectory, 0777, true)) {
        throw new RuntimeException(
            "Could not create temporary build directory."
        );
    }

    $dependencyDirectories = [];

    foreach ($dependencies as $index => $dependency) {
        $dependencyDirectory =
            $tempDirectory
            . '/dependency-'
            . $index;

        if (!mkdir($dependencyDirectory, 0777, true)) {
            throw new RuntimeException(
                "Could not create dependency directory."
            );
        }

        echo "========================================\n";
        echo "Dependency "
            . ($index + 1)
            . "/"
            . count($dependencies)
            . "\n";
        echo "========================================\n";

        echo "URL: "
            . $dependency['url']
            . "\n";

        echo "Branch: "
            . $dependency['branch']
            . "\n";

        echo "Required version: "
            . $dependency['version']
            . "\n\n";


        downloadGitHubRepository(
            $dependency['url'],
            $dependency['branch'],
            $dependencyDirectory
        );

        $repositoryRoot =
            findRepositoryRoot(
                $dependencyDirectory
                . '/repository'
            );

        if ($repositoryRoot === null) {
            throw new RuntimeException(
                "Could not find repository root for: "
                . $dependency['url']
            );
        }

        $virionFile =
            $repositoryRoot
            . '/virion.yml';

        $actualVersion =
            readVirionVersion(
                $virionFile
            );

        echo "Found version: "
            . $actualVersion
            . "\n";

        if ($actualVersion !== $dependency['version']) {
            throw new RuntimeException(
                "Version mismatch for "
                . $dependency['url']
                . "\n"
                . "Expected: "
                . $dependency['version']
                . "\n"
                . "Found: "
                . $actualVersion
            );
        }

        echo "Version matched successfully.\n";

        $srcDirectory =
            $repositoryRoot
            . '/src';

        if (!is_dir($srcDirectory)) {
            throw new RuntimeException(
                "No src/ directory found in dependency: "
                . $dependency['url']
            );
        }

        echo "Found dependency src/: "
            . $srcDirectory
            . "\n\n";

        $dependencyDirectories[] = [
            'url' => $dependency['url'],
            'branch' => $dependency['branch'],
            'version' => $actualVersion,
            'src' => $srcDirectory
        ];
    }

    $phar = new Phar(
        $finalPharFile
    );

    $phar->setStub(
        '<?php __HALT_COMPILER();'
    );

    $phar->startBuffering();

    $pluginYmlPath =
        __DIR__ . '/plugin.yml';

    if (file_exists($pluginYmlPath)) {
        $contents =
            file_get_contents($pluginYmlPath);

        if ($contents === false) {
            throw new RuntimeException(
                "Could not read plugin.yml"
            );
        }

        $phar->addFromString(
            'plugin.yml',
            $contents
        );

        echo "Added plugin.yml\n";
    } else {
        echo "Warning: plugin.yml not found!\n";
    }

    $composerJsonPath =
        __DIR__ . '/composer.json';

    if (file_exists($composerJsonPath)) {
        $contents =
            file_get_contents($composerJsonPath);

        if ($contents === false) {
            throw new RuntimeException(
                "Could not read composer.json"
            );
        }

        $phar->addFromString(
            'composer.json',
            $contents
        );

        echo "Added composer.json\n";
    } else {
        echo "Warning: composer.json not found!\n";
    }

    $composerLockPath =
        __DIR__ . '/composer.lock';

    if (file_exists($composerLockPath)) {
        $contents =
            file_get_contents($composerLockPath);

        if ($contents === false) {
            throw new RuntimeException(
                "Could not read composer.lock"
            );
        }

        $phar->addFromString(
            'composer.lock',
            $contents
        );

        echo "Added composer.lock\n";
    }

    $srcDir =
        __DIR__ . '/src';

    $fileCount = 0;

    if (is_dir($srcDir)) {
        echo "\n";
        echo "Building plugin src/...\n";

        addDirectoryToPhar(
            $phar,
            $srcDir,
            'src',
            $fileCount
        );

        echo "\nAdded "
            . $fileCount
            . " files from plugin src/\n";
    } else {
        echo "Warning: src/ folder not found!\n";
    }

    if (count($dependencyDirectories) > 0) {
        echo "\n";
        echo "========================================\n";
        echo "Bundling dependencies\n";
        echo "========================================\n";

        $dependencyFileCount = 0;

        foreach ($dependencyDirectories as $dependency) {
            echo "\n";

            echo "Dependency: "
                . $dependency['url']
                . "\n";

            echo "Branch: "
                . $dependency['branch']
                . "\n";

            echo "Version: "
                . $dependency['version']
                . "\n";

            addDirectoryToPhar(
                $phar,
                $dependency['src'],
                'src',
                $dependencyFileCount
            );
        }

        echo "\nAdded "
            . $dependencyFileCount
            . " files from dependencies.\n";
    }

    $resourcesDir =
        __DIR__ . '/resources';

    if (is_dir($resourcesDir)) {
        echo "\n";
        echo "Building resources/...\n";

        $resourceCount = 0;

        $iterator =
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $resourcesDir,
                    RecursiveDirectoryIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $realPath =
                $file->getRealPath();

            if ($realPath === false) {
                continue;
            }

            $relativePath =
                substr(
                    $realPath,
                    strlen($resourcesDir) + 1
                );

            $relativePath =
                str_replace(
                    '\\',
                    '/',
                    $relativePath
                );

            $pharPath =
                'resources/'
                . $relativePath;

            if (isset($phar[$pharPath])) {
                throw new RuntimeException(
                    "File conflict detected: "
                    . $pharPath
                );
            }

            $contents =
                file_get_contents($realPath);

            if ($contents === false) {
                throw new RuntimeException(
                    "Could not read resource: "
                    . $realPath
                );
            }

            $phar->addFromString(
                $pharPath,
                $contents
            );

            echo "Added resource: "
                . $pharPath
                . "\n";

            $resourceCount++;
        }

        echo "Added "
            . $resourceCount
            . " resource files from resources/\n";
    } else {
        echo "No resources/ folder found. Skipping.\n";
    }

    $phar->stopBuffering();

    if ($tempDirectory !== null) {
        deleteDirectory(
            $tempDirectory
        );

        $tempDirectory = null;
    }

    echo "\n";
    echo "========================================\n";
    echo " Built $pharFile successfully!\n";
    echo "========================================\n";
    echo "\n";

    echo "Total time: "
        . number_format(
            microtime(true) - $startTime,
            3
        )
        . " seconds\n\n";
    
    $verify =
        new Phar(
            $finalPharFile
        );

    $totalCount = 0;

    echo "Files in PHAR:\n";

    foreach (
        new RecursiveIteratorIterator($verify)
        as $file
    ) {
        if ($totalCount++ < 20) {
            echo "  "
                . $file->getPathName()
                . "\n";
        }
    }

    if ($totalCount > 20) {
        echo "  ... and "
            . ($totalCount - 20)
            . " more files\n";
    }

    echo "\n";

    echo "Total files in PHAR: "
        . $totalCount
        . "\n";

} catch (Throwable $e) {
    if (
        $tempDirectory !== null &&
        is_dir($tempDirectory)
    ) {
        deleteDirectory(
            $tempDirectory
        );
    }

    echo "\n";
    echo "========================================\n";
    echo " Build failed!\n";
    echo "========================================\n";
    echo "\n";

    echo "Error: "
        . $e->getMessage()
        . "\n";

    exit(1);
}
