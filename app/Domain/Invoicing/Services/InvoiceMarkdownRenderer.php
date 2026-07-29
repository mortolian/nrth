<?php

namespace App\Domain\Invoicing\Services;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

class InvoiceMarkdownRenderer
{
    public function toHtml(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            // Single newlines in notes (banking lines, addresses) should show as line breaks.
            'renderer' => [
                'soft_break' => "<br />\n",
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);

        $converter = new MarkdownConverter($environment);

        return (string) $converter->convert($markdown);
    }
}
