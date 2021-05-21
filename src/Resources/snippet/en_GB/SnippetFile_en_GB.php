<?php declare(strict_types=1);

namespace Colo\AfterPay\Resources\snippet\en_GB;

use Shopware\Core\System\Snippet\Files\SnippetFileInterface;

class SnippetFile_en_GB implements SnippetFileInterface
{
    public function getName(): string
    {
        return 'snippets.en-GB';
    }

    public function getPath(): string
    {
        return __DIR__ . '/snippets.en-GB.json';
    }

    public function getIso(): string
    {
        return 'en-GB';
    }

    public function getAuthor(): string
    {
        return 'Arvato AfterPay';
    }

    public function isBase(): bool
    {
        return false;
    }
}
