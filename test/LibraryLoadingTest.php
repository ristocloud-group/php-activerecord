<?php

// The files of the library load one another by an absolute path (__DIR__), never by a relative one. PHP resolves a
// relative path through the include_path and the working directory at every require_once, also for a file it loaded
// already: without OPcache (the CLI default) that cost 5 µs of CPU a call, and SQLBuilder made one or two of them for
// every statement it built (#185). A relative path could also load another file of that name on the include_path.
class LibraryLoadingTest extends SnakeCase_PHPUnit_Framework_TestCase
{
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
