<?php

class LibraryLoadingTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    // The directories of lib/ that composer.json maps to the ActiveRecord\ namespace, relative to lib/ ('' is lib/ itself)
    private const array BASE_DIRS = ['', 'adapters', 'cache', 'exceptions', 'relationships', 'serializers'];

    public function test_the_autoloader_declares_only_utils_up_front(): void
    {
        $declared = json_decode($this->run_in_fresh_process(
            'echo json_encode(array_values(array_filter([...get_declared_classes(), ...get_declared_interfaces()],'
            . ' fn($c) => str_starts_with($c, "ActiveRecord\\\\"))));',
        ), true, flags: JSON_THROW_ON_ERROR);

        $this->assert_same(['ActiveRecord\\Utils'], $declared);
    }

    public function test_every_library_class_autoloads_from_its_own_file(): void
    {
        $files = [];

        foreach ($this->library_files() as $path) {
            $declared = $this->declarations((string) file_get_contents(__DIR__ . "/../lib/$path"));

            if ([] === $declared['classes']) {
                $this->fail("lib/$path declares no class");
            }

            $files['ActiveRecord\\' . $declared['classes'][0]] = (string) realpath(__DIR__ . "/../lib/$path");
        }

        $output = $this->run_in_fresh_process(
            '$wrong = [];'
            . ' foreach (' . var_export($files, true) . ' as $class => $file) {'
            . ' if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) { $wrong[] = "$class does not autoload"; }'
            . ' elseif (realpath((new ReflectionClass($class))->getFileName()) !== $file) { $wrong[] = "$class loads from " . (new ReflectionClass($class))->getFileName(); }'
            . ' }'
            . ' echo json_encode($wrong);',
        );

        $this->assert_same([], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_library_objects_and_statics_work_in_a_fresh_process(): void
    {
        $serialized = serialize(new ActiveRecord\DateTime('2026-01-02 03:04:05'));

        $output = $this->run_in_fresh_process(
            '$serialized = ' . var_export($serialized, true) . ';'
            . <<<'PHP'

                $loaded = fn(string $class): bool => class_exists($class, false);
                // unserialize() loads a class that is not loaded yet
                if (!$loaded('ActiveRecord\DateTime') && unserialize($serialized) instanceof ActiveRecord\DateTime) { echo 'ok '; }
                // a static property read loads its class
                if (!$loaded('ActiveRecord\Serialization') && ActiveRecord\Serialization::$DATETIME_FORMAT !== '') { echo 'ok '; }
                // catch on a class that is not loaded matches nothing and does not load it
                try { throw new \Exception('x'); } catch (ActiveRecord\RecordNotFound $e) { echo 'wrong '; } catch (\Exception $e) { echo 'ok '; }
                if (!$loaded('ActiveRecord\RecordNotFound')) { echo 'ok '; }
                // instanceof on a class that is not loaded is false and does not load it
                if (((new stdClass()) instanceof ActiveRecord\BelongsTo) === false && !$loaded('ActiveRecord\BelongsTo')) { echo 'ok'; }
                PHP,
        );

        $this->assert_same('ok ok ok ok ok', $output);
    }

    // A wrong-case name worked while every class was declared at boot; on a case-sensitive filesystem it now fails on first use
    public function test_library_class_names_are_written_in_their_declared_case(): void
    {
        $classes = $this->library_class_names();
        $offenders = [];

        foreach (['lib', 'test', 'examples'] as $dir) {
            $root = (string) realpath(__DIR__ . "/../$dir");

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ('php' === $file->getExtension()) {
                    foreach ($this->wrong_case_names((string) file_get_contents($file->getPathname()), $classes) as $offender) {
                        $offenders[] = "$dir/" . substr($file->getPathname(), strlen($root) + 1) . ":$offender";
                    }
                }
            }
        }

        sort($offenders);
        $this->assert_same([], $offenders);
    }

    public function test_wrong_case_names_finds_only_a_library_class_in_the_wrong_case(): void
    {
        $code = "<?php\nuse ActiveRecord\\Datetime;\n\$a = new ActiveRecord\\DateTime();\n\$b = new \\ActiveRecord\\readonlyexception();\n"
            . "\$c = new ActiveRecord\\NotALibraryClass(); \$d = ActiveRecord\\Utils::class; \$e = new Other\\datetime();\n";

        $this->assert_same(
            ['2: ActiveRecord\\Datetime', '4: \\ActiveRecord\\readonlyexception'],
            $this->wrong_case_names($code, ['DateTime', 'ReadOnlyException', 'Utils']),
        );
    }

    // The base dirs the guards below read are the ones composer.json maps to ActiveRecord\, so the two cannot drift apart
    public function test_the_psr4_base_dirs_of_composer_json_are_the_ones_the_guards_read(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assert_same(
            array_map(fn(string $dir): string => '' === $dir ? 'lib/' : "lib/$dir/", self::BASE_DIRS),
            $composer['autoload']['psr-4']['ActiveRecord\\'],
        );
    }

    // A file outside the base dirs would never be autoloaded
    public function test_every_library_file_sits_in_a_psr4_base_dir(): void
    {
        $outside = [];

        foreach ($this->library_files() as $path) {
            if (!in_array(dirname($path) === '.' ? '' : dirname($path), self::BASE_DIRS, true)) {
                $outside[] = $path;
            }
        }

        $this->assert_same([], $outside);
    }

    // The base dirs are searched in turn for the same class name, so two files of one name would shadow each other
    public function test_no_file_name_repeats_across_the_base_dirs(): void
    {
        $names = array_map('basename', $this->library_files());

        $this->assert_same([], array_keys(array_filter(array_count_values($names), fn(int $count): bool => $count > 1)));
    }

    // PSR-4 finds a class by its file name: one class per file, named after it, and no loose functions beyond Utils.php
    public function test_every_library_file_declares_one_class_named_after_it(): void
    {
        $wrong = [];

        foreach ($this->library_files() as $path) {
            $declared = $this->declarations((string) file_get_contents(__DIR__ . "/../lib/$path"));

            if ('ActiveRecord' !== $declared['namespace']) {
                $wrong[] = "$path: namespace is " . var_export($declared['namespace'], true);
            }

            if ([basename($path, '.php')] !== $declared['classes']) {
                $wrong[] = "$path: declares [" . implode(', ', $declared['classes']) . ']';
            }

            if ('Utils.php' !== basename($path) && [] !== $declared['functions']) {
                $wrong[] = "$path: declares the function(s) " . implode(', ', $declared['functions']);
            }
        }

        $this->assert_same([], $wrong);
    }

    // Composer loads a class on demand, so no file of lib/ requires or includes another one
    public function test_no_library_file_requires_or_includes_another(): void
    {
        $requiring = [];

        foreach ($this->library_files() as $path) {
            foreach (token_get_all((string) file_get_contents(__DIR__ . "/../lib/$path")) as $token) {
                if (is_array($token) && in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
                    $requiring[] = $path;

                    break;
                }
            }
        }

        $this->assert_same([], $requiring);
    }

    // The helper counts declared classes only: not a ::class constant, not an anonymous class, not a method
    public function test_declarations_reads_classes_functions_and_namespace_from_tokens(): void
    {
        $code = "<?php\nnamespace ActiveRecord;\n// class Commented {}\nclass A { function m() { return B::class; } }\n\$x = new class {};\nfunction f() {}\n";

        $this->assert_same(['namespace' => 'ActiveRecord', 'classes' => ['A'], 'functions' => ['f']], $this->declarations($code));
    }

    /**
     * The *.php files under lib/, as paths relative to lib/ (for example relationships/AbstractRelationship.php), sorted.
     *
     * @return list<string>
     */
    private function library_files(): array
    {
        $root = (string) realpath(__DIR__ . '/../lib');
        $paths = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('php' === $file->getExtension()) {
                $paths[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * The class names declared by the files of lib/ (without the ActiveRecord\\ prefix).
     *
     * @return list<string>
     */
    private function library_class_names(): array
    {
        $names = [];

        foreach ($this->library_files() as $path) {
            $names = [...$names, ...$this->declarations((string) file_get_contents(__DIR__ . "/../lib/$path"))['classes']];
        }

        return $names;
    }

    /**
     * The names in $code, as "line: name", that start with ActiveRecord\\ and spell a library class in a case other than
     * the declared one.
     *
     * @param list<string> $classes The library class names, in their declared case
     * @return list<string>
     */
    private function wrong_case_names(string $code, array $classes): array
    {
        $offenders = [];

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && 1 === preg_match('/^\\\\?ActiveRecord\\\\(.+)$/', $token[1], $m)) {
                foreach ($classes as $class) {
                    if (0 === strcasecmp($class, $m[1]) && $class !== $m[1]) {
                        $offenders[] = "$token[2]: $token[1]";
                    }
                }
            }
        }

        return $offenders;
    }

    /**
     * The namespace, the classes (class, interface, trait and enum) and the functions declared at brace depth 0 of $code.
     *
     * @return array{namespace: ?string, classes: list<string>, functions: list<string>}
     */
    private function declarations(string $code): array
    {
        $namespace = null;
        $classes = [];
        $functions = [];
        $depth = 0;
        $tokens = array_values(array_filter(
            token_get_all($code),
            fn(array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        foreach ($tokens as $i => $token) {
            if ('{' === $token || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ('}' === $token) {
                $depth--;
            } elseif (is_array($token)) {
                $previous = $tokens[$i - 1] ?? null;
                $after_double_colon = is_array($previous) && T_DOUBLE_COLON === $previous[0];

                if (T_NAMESPACE === $token[0] && is_array($tokens[$i + 1]) && in_array($tokens[$i + 1][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                    $namespace = $tokens[$i + 1][1];
                } elseif (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && !$after_double_colon && is_array($tokens[$i + 1]) && T_STRING === $tokens[$i + 1][0]) {
                    $classes[] = $tokens[$i + 1][1];
                } elseif (T_FUNCTION === $token[0] && 0 === $depth && is_array($tokens[$i + 1]) && T_STRING === $tokens[$i + 1][0]) {
                    $functions[] = $tokens[$i + 1][1];
                }
            }
        }

        return ['namespace' => $namespace, 'classes' => $classes, 'functions' => $functions];
    }

    /**
     * Runs $code in a new PHP process that has loaded nothing but vendor/autoload.php, and returns its stdout.
     */
    private function run_in_fresh_process(string $code): string
    {
        $autoload = (string) realpath(__DIR__ . '/../vendor/autoload.php');
        $process = proc_open([PHP_BINARY, '-d', 'pcov.enabled=0'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assert_true(is_resource($process));

        fwrite($pipes[0], "<?php require '$autoload'; " . $code);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assert_same(0, proc_close($process), $stderr);
        $this->assert_same('', $stderr);

        return $stdout;
    }
}
