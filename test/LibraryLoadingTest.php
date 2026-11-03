<?php

// The files of the library load one another by an absolute path (__DIR__), never by a relative one. PHP resolves a
// relative path through the include_path and the working directory at every require_once, also for a file it loaded
// already: without OPcache (the CLI default) that cost 5 µs of CPU a call, and SQLBuilder made one or two of them for
// every statement it built (#185). A relative path could also load another file of that name on the include_path.
class LibraryLoadingTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    // The directories of lib/ that composer.json maps to the ActiveRecord\ namespace, relative to lib/ ('' is lib/ itself)
    private const array BASE_DIRS = ['', 'adapters', 'cache', 'exceptions', 'relationships', 'serializers'];

    // The files that still declare more than one class; a split removes its entry
    private const array MULTI_CLASS_FILES = ['Exceptions.php', 'Inflector.php', 'Relationship.php', 'Serialization.php', 'Validations.php'];

    // The files that still require or include another one; a split removes its entry
    private const array REQUIRING_FILES = [
        'Cache.php', 'ConditionCompiler.php', 'Conditions.php', 'Connection.php', 'Model.php', 'Relationship.php', 'SQLBuilder.php', 'Table.php',
    ];

    public function test_the_library_files_load_one_another_by_an_absolute_path(): void
    {
        $relative = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../lib', FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ('php' === $file->getExtension()) {
                foreach ($this->literal_includes((string) file_get_contents($file->getPathname())) as [$line, $path]) {
                    $relative[] = $file->getFilename() . ":$line $path";
                }
            }
        }

        $this->assert_same([], $relative);
    }

    // The guard reads the code, not the comments: an include whose path starts with a string literal is relative
    public function test_an_include_of_a_literal_path_is_found_and_one_of_an_absolute_path_is_not(): void
    {
        $code = "<?php\n// require_once 'Commented.php';\nrequire_once 'Relative.php';\nrequire_once(\"Relative.php\");\n"
            . "require_once __DIR__ . '/Absolute.php';\ninclude \$path;\n";

        $this->assert_same([[3, "'Relative.php'"], [4, '"Relative.php"']], $this->literal_includes($code));
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

            if (in_array($path, self::MULTI_CLASS_FILES, true)) {
                if (count($declared['classes']) < 2) {
                    $wrong[] = "$path: listed in MULTI_CLASS_FILES but declares " . count($declared['classes']) . ' class(es)';
                }

                continue;
            }

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

    // Composer loads a class on demand, so a file that requires another one is a leftover of the manifest
    public function test_no_library_file_requires_another(): void
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

        $expected = self::REQUIRING_FILES;
        sort($expected);
        sort($requiring);

        $this->assert_same($expected, $requiring);
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
     * The require and include statements of $code whose path starts with a string literal, by line.
     *
     * @return list<array{int, string}>
     */
    private function literal_includes(string $code): array
    {
        $includes = [];
        $tokens = array_values(array_filter(
            token_get_all($code),
            fn(array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        foreach ($tokens as $i => $token) {
            if (is_array($token) && in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
                $path = '(' === $tokens[$i + 1] ? $tokens[$i + 2] : $tokens[$i + 1];

                if (is_array($path) && T_CONSTANT_ENCAPSED_STRING === $path[0]) {
                    $includes[] = [$token[2], $path[1]];
                }
            }
        }

        return $includes;
    }
}
