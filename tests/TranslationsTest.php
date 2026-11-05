<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;

const FMM_NAMESPACE = 'filament-media-manager';

/**
 * Allow-list of legitimate literals: "relative/path:line-pattern" => reason.
 */
function fmmAllowList(): array
{
    return [];
}

function fmmPackagePath(string $path = ''): string
{
    return dirname(__DIR__) . ($path === '' ? '' : '/' . $path);
}

/**
 * @return array<int, string>
 */
function fmmScannedFiles(): array
{
    $files = [];

    foreach (['src', 'resources/views'] as $folder) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(fmmPackagePath($folder), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * @return array<int, array{file: string, line: int, text: string}>
 */
function fmmFindings(string $regex, ?array $only = null): array
{
    $allowList = fmmAllowList();
    $findings = [];

    foreach (fmmScannedFiles() as $path) {
        $relative = str_replace(fmmPackagePath() . '/', '', $path);

        if (str_starts_with($relative, 'src/Demo/')) {
            continue;
        }

        $isBlade = str_ends_with($path, '.blade.php');

        if ($only !== null && ($only['blade'] ?? null) !== null && $only['blade'] !== $isBlade) {
            continue;
        }

        foreach (file($path) as $index => $line) {
            if (! preg_match($regex, $line)) {
                continue;
            }

            foreach ($allowList as $key => $reason) {
                [$allowedFile, $pattern] = explode(':', $key, 2);

                if ($allowedFile === $relative && preg_match('/' . $pattern . '/', $line)) {
                    continue 2;
                }
            }

            $findings[] = ['file' => $relative, 'line' => $index + 1, 'text' => trim($line)];
        }
    }

    return $findings;
}

function fmmFormat(array $findings): string
{
    return collect($findings)->map(fn (array $f): string => "{$f['file']}:{$f['line']}  {$f['text']}")->implode("\n");
}

it('has a fr file with the same keys as every en file and no empty values', function () {
    $enFiles = glob(fmmPackagePath('resources/lang/en/*.php'));

    expect($enFiles)->not->toBeEmpty();

    foreach ($enFiles as $enFile) {
        $frFile = fmmPackagePath('resources/lang/fr/' . basename($enFile));

        expect(file_exists($frFile))->toBeTrue(basename($enFile) . ' is missing in fr');

        $en = Arr::dot(require $enFile);
        $fr = Arr::dot(require $frFile);

        expect(array_keys($fr))->toEqualCanonicalizing(array_keys($en));

        foreach ([$en, $fr] as $dotted) {
            foreach ($dotted as $key => $value) {
                expect(trim((string) $value))->not->toBe('', "{$key} is empty");
            }
        }
    }
});

it('only references keys that exist in en', function () {
    $missing = [];
    $count = 0;

    foreach (fmmScannedFiles() as $path) {
        $source = file_get_contents($path);

        preg_match_all('/(?:__|trans|trans_choice|@lang)\(\s*([\'"])(' . preg_quote(FMM_NAMESPACE, '/') . '::[^\'"]+)\1/', $source, $matches);

        foreach ($matches[2] as $key) {
            $count++;

            if (! Lang::has($key, 'en', false)) {
                $missing[] = str_replace(fmmPackagePath() . '/', '', $path) . " → {$key}";
            }
        }
    }

    expect($count)->toBeGreaterThan(0);
    expect($missing)->toBe([]);
});

it('has no JSON-style translation calls', function () {
    $regex = '/(?:__|trans|trans_choice|@lang)\(\s*[\'"](?!' . preg_quote(FMM_NAMESPACE, '/') . '::|validation\.)/';

    expect(fmmFormat(fmmFindings($regex)))->toBe('');
});

it('has no string literal passed to a label-like method', function () {
    $methods = 'label|heading|placeholder|helperText|hint|tooltip|description|modalHeading|modalDescription|modalSubmitActionLabel|modalCancelActionLabel|title|body|emptyStateHeading|emptyStateDescription|navigationLabel|successNotificationTitle|badge';

    expect(fmmFormat(fmmFindings('/->(' . $methods . ')\(\s*[\'"][^\'"$]+[\'"]/')))->toBe('');
});

it('has no accented letter in PHP or Blade files', function () {
    expect(fmmFormat(fmmFindings('/[À-ÖØ-öø-ÿ]/u')))->toBe('');
});

/**
 * Blade sources reduced to their visible markup: echoes, comments, directives and tags removed,
 * so that PHP/JS operators such as `=>` or `<=` cannot pass for literal text.
 */
function fmmBladeMarkup(string $source, bool $keepTags): string
{
    $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    $source = preg_replace('/@php\b(?!\().*?@endphp/s', '', $source);
    $source = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', '', $source);
    $source = preg_replace('/@\w+\s*(\((?:[^()]|(?1))*\))?/', '', $source);

    if (! $keepTags) {
        $source = preg_replace('/<\/?[a-zA-Z][^>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^>"\']*)*>/s', '', $source);
    }

    return $source;
}

it('has no literal text in views', function () {
    $allowList = fmmAllowList();
    $offenders = [];

    foreach (fmmScannedFiles() as $path) {
        if (! str_ends_with($path, '.blade.php')) {
            continue;
        }

        $relative = str_replace(fmmPackagePath() . '/', '', $path);
        $source = file_get_contents($path);

        $texts = [];

        foreach (preg_split('/\R/', fmmBladeMarkup($source, false)) as $line) {
            if (preg_match('/[A-Za-z][^<{@]{2,}/', $line)) {
                $texts[] = trim($line);
            }
        }

        preg_match_all('/(?:title|aria-label|placeholder|alt)="[A-Za-z][^"{]*"/', fmmBladeMarkup($source, true), $attributes);

        foreach (array_merge($texts, $attributes[0]) as $text) {
            foreach ($allowList as $key => $reason) {
                [$allowedFile, $pattern] = explode(':', $key, 2);

                if ($allowedFile === $relative && preg_match('/' . $pattern . '/', $text)) {
                    continue 2;
                }
            }

            $offenders[] = "{$relative}  {$text}";
        }
    }

    expect(implode("\n", $offenders))->toBe('');
});

it('has no literal return from the label-like overrides', function () {
    $regex = '/function (?:getNavigationLabel|getTitle|getNavigationGroup)\b/';
    $offenders = [];

    foreach (fmmFindings($regex) as $finding) {
        $lines = file(fmmPackagePath($finding['file']));
        $body = implode('', array_slice($lines, $finding['line'] - 1, 4));

        if (preg_match('/return\s+[\'"]/', $body)) {
            $offenders[] = "{$finding['file']}:{$finding['line']}";
        }
    }

    expect($offenders)->toBe([]);
});
