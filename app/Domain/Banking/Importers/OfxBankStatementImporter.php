<?php

namespace App\Domain\Banking\Importers;

use App\Domain\Banking\Contracts\BankingStatementImporter;
use App\Domain\Banking\DTOs\ParsedBankStatementDTO;
use App\Domain\Banking\DTOs\ParsedTransactionDTO;
use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Support\AmountParser;
use App\Domain\Banking\Support\DateParser;

/**
 * Minimal OFX 1.x SGML/XML parser for bank statement imports.
 */
final class OfxBankStatementImporter implements BankingStatementImporter
{
    public function __construct(
        private readonly AmountParser $amountParser,
        private readonly DateParser $dateParser,
    ) {}

    public function supports(string $mimeType, string $extension): bool
    {
        $extension = strtolower(ltrim($extension, '.'));

        return $extension === 'ofx'
            || in_array($mimeType, ['application/x-ofx', 'application/ofx', 'text/ofx'], true);
    }

    public function parse(string $path, array $options = []): ParsedBankStatementDTO
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Unable to read OFX file.');
        }

        $blocks = $this->extractStmtTrnBlocks($content);
        $transactions = [];
        $currency = $this->extractTag($content, 'CURDEF')
            ?? $this->extractTag($content, 'CURRENCY');

        foreach ($blocks as $block) {
            $transaction = $this->parseTransactionBlock($block, $currency);
            if ($transaction !== null) {
                $transactions[] = $transaction;
            }
        }

        return new ParsedBankStatementDTO(
            transactions: $transactions,
            metadata: ['currency' => $currency],
        );
    }

    /**
     * @return list<string>
     */
    private function extractStmtTrnBlocks(string $content): array
    {
        if (preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $content, $xmlMatches)) {
            return $xmlMatches[1];
        }

        if (preg_match_all('/<STMTTRN>(.*?)(?=<STMTTRN>|<\/BANKTRANLIST>|<\/CCSTMTTRN>|<\/STMTTRNRS>|<\/CCSTMTTRNRS>)/is', $content, $sgmlMatches)) {
            return $sgmlMatches[1];
        }

        return [];
    }

    private function parseTransactionBlock(string $block, ?string $defaultCurrency): ?ParsedTransactionDTO
    {
        $amountRaw = $this->extractTag($block, 'TRNAMT');
        $dateRaw = $this->extractTag($block, 'DTPOSTED') ?? $this->extractTag($block, 'DTUSER');
        $memo = $this->extractTag($block, 'MEMO') ?? $this->extractTag($block, 'NAME') ?? '';
        $fitId = $this->extractTag($block, 'FITID');
        $currency = $this->extractTag($block, 'CURRENCY') ?? $defaultCurrency;

        $amount = $this->amountParser->parse($amountRaw);
        $transactionDate = $this->parseOfxDate($dateRaw);

        if ($amount === null || $transactionDate === null || trim($memo) === '') {
            return null;
        }

        $direction = bccomp($amount, '0', 2) < 0
            ? TransactionDirection::Debit
            : TransactionDirection::Credit;

        return new ParsedTransactionDTO(
            transactionDate: $transactionDate,
            description: trim($memo),
            amount: ltrim($amount, '-'),
            direction: $direction,
            reference: $fitId,
            currency: $currency,
            metadata: ['fitid' => $fitId],
        );
    }

    private function extractTag(string $content, string $tag): ?string
    {
        if (preg_match('/<'.$tag.'>([^<\r\n]+)/i', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function parseOfxDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) < 8) {
            return null;
        }

        $ymd = substr($digits, 0, 8);

        return $this->dateParser->parse(
            substr($ymd, 0, 4).'-'.substr($ymd, 4, 2).'-'.substr($ymd, 6, 2),
            'Y-m-d'
        );
    }
}
