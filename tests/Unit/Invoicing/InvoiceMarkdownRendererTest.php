<?php

namespace Tests\Unit\Invoicing;

use App\Domain\Invoicing\Services\InvoiceMarkdownRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvoiceMarkdownRendererTest extends TestCase
{
    #[Test]
    public function it_preserves_single_newlines_as_line_breaks(): void
    {
        $html = app(InvoiceMarkdownRenderer::class)->toHtml("Bank: FNB\nAccount: 123456\n**Reference:** invoice");

        $this->assertStringContainsString('<br', $html);
        $this->assertStringContainsString('Bank: FNB', $html);
        $this->assertStringContainsString('Account: 123456', $html);
        $this->assertStringContainsString('<strong>Reference:</strong>', $html);
    }

    #[Test]
    public function it_returns_empty_string_for_blank_input(): void
    {
        $renderer = app(InvoiceMarkdownRenderer::class);

        $this->assertSame('', $renderer->toHtml(null));
        $this->assertSame('', $renderer->toHtml('   '));
    }
}
