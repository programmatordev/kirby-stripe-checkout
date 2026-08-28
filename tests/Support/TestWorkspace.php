<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support;

use Kirby\Data\Txt;
use Kirby\Data\Yaml;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use RuntimeException;

final class TestWorkspace
{
    private bool $removed = false;

    private function __construct(private readonly string $root)
    {
        foreach ($this->roots() as $path) {
            if (Dir::make($path) !== true) {
                throw new RuntimeException('Unable to create test directory: ' . $path);
            }
        }
    }

    public function __destruct()
    {
        $this->remove();
    }

    public static function create(): self
    {
        $name = sprintf('%d-%s', getmypid(), bin2hex(random_bytes(8)));

        return new self(self::baseRoot() . DIRECTORY_SEPARATOR . $name);
    }

    public static function baseRoot(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'kirby-stripe-checkout-tests';
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @param array<string, mixed> $content */
    public function writeDraftPage(string $slug, string $template, array $content): void
    {
        $root = $this->roots()['content'] . '/_drafts/' . $slug;

        if (Dir::make($root) !== true || F::write($root . '/' . $template . '.txt', Txt::encode($content)) === false) {
            throw new RuntimeException('Unable to prepare draft Page fixture: ' . $slug);
        }
    }

    /** @param array<string, mixed> $blueprint */
    public function writePageBlueprint(string $name, array $blueprint): void
    {
        $root = $this->roots()['site'] . '/blueprints/pages';

        if (Dir::make($root) !== true || F::write($root . '/' . $name . '.yml', Yaml::encode($blueprint)) === false) {
            throw new RuntimeException('Unable to prepare Page blueprint fixture: ' . $name);
        }
    }

    /**
     * @return array<string, string>
     */
    public function roots(): array
    {
        return [
            'index' => $this->root,
            'content' => $this->root . '/content',
            'media' => $this->root . '/media',
            'site' => $this->root . '/site',
            'accounts' => $this->root . '/site/accounts',
            'cache' => $this->root . '/site/cache',
            'logs' => $this->root . '/site/logs',
            'sessions' => $this->root . '/site/sessions',
        ];
    }

    public function remove(): void
    {
        if ($this->removed === true) {
            return;
        }

        $expectedPrefix = self::baseRoot() . DIRECTORY_SEPARATOR;

        if (str_starts_with($this->root, $expectedPrefix) === false) {
            throw new RuntimeException('Refusing to remove an unexpected test path: ' . $this->root);
        }

        if (is_dir($this->root) === true && Dir::remove($this->root) !== true) {
            throw new RuntimeException('Unable to remove test directory: ' . $this->root);
        }

        $this->removed = true;

        // Remove the shared parent only when no other test workspace uses it.
        @rmdir(self::baseRoot());
    }
}
