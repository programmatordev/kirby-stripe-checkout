<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Development\PhpCsFixer;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Analyzer\WhitespacesAnalyzer;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

/** Separates complete braced control statements, not arbitrary closing braces. */
final class BlankLineAfterControlStructureFixer extends AbstractFixer implements WhitespacesAwareFixerInterface
{
    public function getName(): string
    {
        return 'StripeCheckout/blank_line_after_control_structure';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Separate complete control blocks from following statements with a blank line.',
            [new CodeSample("<?php\nif (\$ready) {\n    run();\n}\nfinish();\n")],
        );
    }

    public function getPriority(): int
    {
        // Run after brace normalization and the built-in before-statement rule.
        return -22;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound([T_IF, T_FOR, T_FOREACH, T_WHILE, T_DO, T_SWITCH, T_TRY]);
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        // Work backwards so inserting whitespace cannot move unvisited tokens.
        for ($index = count($tokens) - 1; $index > 0; $index--) {
            if ($tokens[$index]->equals('}') === false) {
                continue;
            }

            $open = $tokens->findBlockStart(Tokens::BLOCK_TYPE_BRACE, $index);
            $keyword = $tokens->getPrevMeaningfulToken($open);

            if ($keyword !== null && $tokens[$keyword]->equals(')')) {
                $condition = $tokens->findBlockStart(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $keyword);
                $keyword = $tokens->getPrevMeaningfulToken($condition);
            }

            if ($keyword === null || $tokens[$keyword]->isGivenKind([
                T_IF, T_ELSEIF, T_ELSE, T_FOR, T_FOREACH, T_WHILE,
                T_DO, T_SWITCH, T_TRY, T_CATCH, T_FINALLY,
            ]) === false) {
                continue;
            }

            $end = $index;

            if ($tokens[$keyword]->isGivenKind(T_DO)) {
                // The statement ends after `while (...);`, not after the do body.
                $while = $tokens->getNextMeaningfulToken($end);
                $condition = $while === null ? null : $tokens->getNextMeaningfulToken($while);

                if ($condition === null || $tokens[$condition]->equals('(') === false) {
                    continue;
                }

                $close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $condition);
                $semicolon = $tokens->getNextMeaningfulToken($close);

                if ($semicolon === null || $tokens[$semicolon]->equals(';') === false) {
                    continue;
                }

                $end = $semicolon;
            }

            $next = $tokens->getNextMeaningfulToken($end);

            if ($next === null || $tokens[$next]->equals('}') || $tokens[$next]->isGivenKind([
                T_ELSE, T_ELSEIF, T_CATCH, T_FINALLY, T_CLOSE_TAG,
            ])) {
                continue;
            }

            $this->separate($tokens, $end);
        }
    }

    private function separate(Tokens $tokens, int $end): void
    {
        // Keep same-line comments attached to the completed block. A comment
        // on its own next line belongs to the following section instead.
        while (($next = $tokens->getNextNonWhitespace($end)) !== null && $tokens[$next]->isComment()) {
            $gap = $tokens[$end + 1]->isWhitespace() ? $tokens[$end + 1]->getContent() : '';

            if (str_contains($gap, "\n")) {
                break;
            }

            $end = $next;
        }

        $whitespace = $tokens[$end + 1]->isWhitespace() ? $tokens[$end + 1]->getContent() : '';
        $newlines = substr_count($whitespace, "\n");

        if ($newlines >= 2) {
            return;
        }

        $lineEnding = $this->whitespacesConfig->getLineEnding();
        $replacement = $newlines === 1
            ? $lineEnding . $whitespace
            : $lineEnding . $lineEnding . WhitespacesAnalyzer::detectIndent($tokens, $end);
        $tokens->ensureWhitespaceAtIndex($end + 1, 0, $replacement);
    }
}
