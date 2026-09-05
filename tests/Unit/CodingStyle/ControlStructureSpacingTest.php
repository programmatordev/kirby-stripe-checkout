<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\CodingStyle;

use PhpCsFixer\ConfigInterface;
use PhpCsFixer\Fixer\Whitespace\BlankLineBeforeStatementFixer;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\WhitespacesFixerConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Development\PhpCsFixer\BlankLineAfterControlStructureFixer;
use SplFileInfo;

require_once dirname(__DIR__, 3) . '/tools/PhpCsFixer/BlankLineAfterControlStructureFixer.php';

final class ControlStructureSpacingTest extends TestCase
{
    #[DataProvider('blocks')]
    public function testSeparatesCompleteBlocksAndIsIdempotent(string $body): void
    {
        $fixture = "<?php\nfunction example() {\n" . $body . "\n}\n";
        $input = str_replace("<blank>\n", '', $fixture);
        $expected = str_replace("<blank>\n", "\n", $fixture);
        $this->assertSame($expected, $this->format($input));
        $this->assertSame($expected, $this->format($expected));
    }

    /** @return iterable<string, array{string}> */
    public static function blocks(): iterable
    {
        foreach (['if ($ready)', 'for ($i = 0; $i < 2; $i++)', 'foreach ($items as $item)', 'while ($ready)'] as $head) {
            yield $head => ["    {$head} {\n        run();\n    }\n<blank>\n    finish();"];
        }

        yield 'if else chain' => ["    if (true) {\n        a();\n    } elseif (false) {\n        b();\n    } else {\n        c();\n    }\n<blank>\n    finish();"];
        yield 'try catch finally' => ["    try {\n        a();\n    } catch (Exception \$e) {\n        b();\n    } finally {\n        c();\n    }\n<blank>\n    finish();"];
        yield 'try catch' => ["    try {\n        a();\n    } catch (Exception \$e) {\n        b();\n    }\n<blank>\n    finish();"];
        yield 'switch' => ["    switch (\$value) {\n        case 1:\n            break;\n    }\n<blank>\n    finish();"];
        yield 'do while' => ["    do {\n        run();\n    } while (ready());\n<blank>\n    finish();"];
        yield 'nested blocks' => ["    if (true) {\n        if (false) {\n            a();\n        }\n<blank>\n        b();\n    }\n<blank>\n    finish();"];
        yield 'end of function' => ["    if (true) {\n        run();\n    }"];
        yield 'end of parent block' => ["    while (true) {\n        if (true) {\n            break;\n        }\n    }"];
        yield 'end of do while' => ["    do {\n        run();\n    } while (ready());"];
        yield 'following comment' => ["    if (true) {\n        a();\n    }\n<blank>\n    // Start the next task.\n    b();"];
        yield 'attached line comment' => ["    if (true) {\n        a();\n    } // Done.\n<blank>\n    b();"];
        yield 'attached block comments' => ["    if (true) {\n        a();\n    } /* Done. */ /* Still attached. */\n<blank>\n    b();"];
        yield 'comment at enclosing end' => ["    if (true) {\n        a();\n    }\n    // No following statement."];
        yield 'comments between continuations' => ["    if (true) {\n        a();\n    } /* alternate */ else {\n        b();\n    }\n<blank>\n    c();"];
        yield 'closures and match untouched' => ["    \$callback = function () {\n        a();\n    };\n    \$result = match (true) {\n        true => 1,\n        default => 2,\n    };\n    b();"];
        yield 'anonymous class untouched' => ["    \$object = new class {\n        public function run() {\n            a();\n        }\n    };\n    b();"];
    }

    public function testBeforeAndAfterRulesRespectBlockBoundaries(): void
    {
        $input = "<?php\nfunction example() {\n    if (true) {\n        a();\n    }\n    b();\n    if (false) {\n        c();\n    }\n}\n";
        $expected = "<?php\nfunction example() {\n    if (true) {\n        a();\n    }\n\n    b();\n\n    if (false) {\n        c();\n    }\n}\n";
        $tokens = Tokens::fromCode($input);
        $before = new BlankLineBeforeStatementFixer();
        $before->configure(['statements' => ['if']]);
        $before->fix(new SplFileInfo('example.php'), $tokens);
        (new BlankLineAfterControlStructureFixer())->fix(new SplFileInfo('example.php'), $tokens);
        $this->assertSame($expected, $tokens->generateCode());
    }

    public function testUsesConfiguredLineEndingsAndPreservesIndentation(): void
    {
        $fixer = new BlankLineAfterControlStructureFixer();
        $fixer->setWhitespacesConfig(new WhitespacesFixerConfig("\t", "\r\n"));
        $input = "<?php\r\nfunction example() {\r\n\tif (true) {\r\n\t\ta();\r\n\t}\r\n\tb();\r\n}\r\n";
        $tokens = Tokens::fromCode($input);
        $fixer->fix(new SplFileInfo('example.php'), $tokens);
        $this->assertSame(str_replace("\t}\r\n\tb();", "\t}\r\n\r\n\tb();", $input), $tokens->generateCode());
    }

    public function testFileEndAndClosingTagAreNotPadded(): void
    {
        foreach (["<?php\nif (true) {\n    a();\n}\n", "<?php\nif (true) {\n    a();\n}\n?>"] as $input) {
            $this->assertSame($input, $this->format($input));
        }
    }

    public function testCanSeparateStatementsOnTheSameLine(): void
    {
        $input = "<?php\nif (true) { a(); }b();\n";
        $this->assertSame("<?php\nif (true) { a(); }\n\nb();\n", $this->format($input));
    }

    public function testProjectConfigurationRegistersBothRules(): void
    {
        $config = require dirname(__DIR__, 3) . '/.php-cs-fixer.dist.php';
        $this->assertInstanceOf(ConfigInterface::class, $config);
        $rules = $config->getRules();
        $this->assertSame(['statements' => ['if', 'for', 'foreach', 'while', 'do', 'switch', 'try']], $rules['blank_line_before_statement']);
        $this->assertTrue($rules['StripeCheckout/blank_line_after_control_structure']);
    }

    private function format(string $input): string
    {
        $tokens = Tokens::fromCode($input);
        (new BlankLineAfterControlStructureFixer())->fix(new SplFileInfo('example.php'), $tokens);

        return $tokens->generateCode();
    }
}
